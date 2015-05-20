<?php

namespace Voryx\ThruwayBundle;

use JMS\Serializer\SerializationContext;
use JMS\Serializer\Serializer;
use React\Promise\Promise;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\User;
use Symfony\Component\Security\Core\User\UserInterface;
use Thruway\CallResult;
use Thruway\ClientSession;
use Thruway\Peer\Client;
use Thruway\Transport\TransportInterface;
use Voryx\ThruwayBundle\Annotation\Register;
use Voryx\ThruwayBundle\Annotation\Subscribe;
use Voryx\ThruwayBundle\Event\SessionEvent;
use Voryx\ThruwayBundle\Mapping\MappingInterface;
use Voryx\ThruwayBundle\Mapping\URIClassMapping;

/**
 * Class WampKernel
 *
 * @package Voryx\ThruwayBundle
 */
class WampKernel implements HttpKernelInterface
{

    /* @var $session ClientSession */
    private $session;

    /**
     * @var TransportInterface
     */
    private $transport;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var Serializer
     */
    private $serializer;

    /**
     * @var ResourceMapper
     */
    private $resourceMapper;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @var null
     */
    private $processName;

    /**
     * @var
     */
    private $processInstance;


    /**
     * @param ContainerInterface $container
     * @param Serializer $serializer
     * @param ResourceMapper $resourceMapper
     */
    function __construct(
        ContainerInterface $container,
        Serializer $serializer,
        ResourceMapper $resourceMapper,
        EventDispatcherInterface $dispatcher
    ) {
        $this->container      = $container;
        $this->serializer     = $serializer;
        $this->resourceMapper = $resourceMapper;
        $this->dispatcher     = $dispatcher;
    }

    /**
     * @param ClientSession $session
     * @param TransportInterface $transport
     */
    public function onOpen(ClientSession $session, TransportInterface $transport)
    {
        $this->session   = $session;
        $this->transport = $transport;
        $mappings        = $this->resourceMapper->getMappings($this->getProcessName());

        $event = new SessionEvent($session, $transport, $this->processName, $this->processInstance, $mappings);
        $this->dispatcher->dispatch(WampEvents::OPEN, $event);

        //Map RPC calls and subscriptions to their controllers
        $this->mapResources($mappings);

    }

    /**
     * Go through all of the resource mappings for this worker process and create the corresponding WAMP URIs
     * @param $mappings
     */
    protected function mapResources($mappings)
    {
        /* @var $mapping URIClassMapping */
        foreach ($mappings as $mapping) {
            if ($mapping->getAnnotation() instanceof Register) {

                $this->createRPC($mapping);

                $this->sendAuthorization($mapping, 'call');

            } elseif ($mapping->getAnnotation() instanceof Subscribe) {

                $this->createSubscribe($mapping);

                $this->sendAuthorization($mapping, 'subscribe');
            }
        }
    }

    /**
     * Register an RPC
     *
     * @param MappingInterface $mapping
     */
    protected function createRPC(MappingInterface $mapping)
    {
        /* @var $annotation Register */
        $annotation       = $mapping->getAnnotation();
        $multiRegister    = $annotation->getMultiRegister() !== null ? $annotation->getMultiRegister() : true;
        $discloseCaller   = $annotation->getDiscloseCaller() !== null ? $annotation->getDiscloseCaller() : true;
        $object           = $this->container->get($mapping->getServiceId());
        $registerCallback = $annotation->getRegisterCallback() ? [$object, $annotation->getRegisterCallback()] : null;

        /**
         * If this isn't the first worker process to be created, we can't register this RPC call again.
         */
        if ($this->getProcessInstance() > 0 && !$multiRegister) {
            return;
        }

        //RPC Options
        $callOptions = [
            'disclose_caller'          => $discloseCaller,
            "thruway_multiregister"    => $multiRegister,
            "replace_orphaned_session" => $annotation->getReplaceOrphanedSession()
        ];

        //RPC Callback
        $rpcCallback = function ($args, $kwargs, $details) use ($mapping) {
            return $this->handleRPC($args, $kwargs, $details, $mapping);
        };

        //Register the RPC Call
        $this->session->register($annotation->getName(), $rpcCallback, $callOptions)->then($registerCallback);
    }


    /**
     * Handle the RPC
     *
     * @param $args
     * @param $kwargs
     * @param $details
     * @param MappingInterface $mapping
     * @return mixed|static
     * @throws \Exception
     */
    protected function handleRPC($args, $kwargs, $details, MappingInterface $mapping)
    {
        //@todo match up $kwargs to the method arguments

        try {
            //Force cleanup before making the call
            $this->cleanup();

            $controller     = $this->container->get($mapping->getServiceId());
            $controllerArgs = $this->deserializeArgs($args, $mapping);

            if ($controller instanceof ContainerAwareInterface) {

                $reflectController = new \ReflectionClass($controller);

                $containerProperty = $reflectController->getProperty('container');
                $containerProperty->setAccessible(true);
                $container = $containerProperty->getValue($controller);

                $user = $this->authenticateAuthId($details->authid, $container);

                // Newer version of symfony have Controller::getUser(), so this isn't really needed anymore.  Leaving this here for BC.
                //Inject the User object if the UserAware trait in in use
                $traits = class_uses($controller);
                if (isset($traits['Voryx\ThruwayBundle\DependencyInjection\UserAwareTrait'])) {

                    if ($user) {
                        $controller->setUser($user);
                    }
                }
            }


            // Disabled this for now since it conflicts with the deserializeArgs
            // @todo make this an option so you can use one or the other
            //Dispatch Controller Events
            //$this->dispatchControllerEvents($controller, $mapping);

            //Call Controller
            $rawResult = call_user_func_array([$controller, $mapping->getMethod()->getName()], $controllerArgs);

            //Create a serialization context
            $context = $this->createSerializationContext($mapping);

            //Do clean on the controller
            $this->cleanup($controller);

            if ($rawResult instanceof Promise) {
                return $rawResult->then(function ($d) use ($context) {
                    //If the data is a CallResult, we only want to serialize the first argument
                    $d = $d instanceof CallResult ? [$d[0]] : $d;
                    return json_decode($this->serializer->serialize($d, "json", $context));
                });
            } else {
                return json_decode($this->serializer->serialize($rawResult, "json", $context));
            }

        } catch (\Exception $e) {
            $this->cleanup();
            $message = "Unable to make the call: {$mapping->getAnnotation()->getName()} \n Message:  {$e->getMessage()}";
            $this->container->get('logger')->critical($message);
            throw new \Exception($message);
        }
    }

    /**
     * Subscribe to a topic
     *
     * @param MappingInterface $mapping
     */
    protected function createSubscribe(MappingInterface $mapping)
    {
        $topic = $mapping->getAnnotation()->getName();

        $subscribeCallback = function ($args) use ($mapping) {
            $this->handleEvent($args, $mapping);
        };

        //Subscribe to a topic
        $this->session->subscribe($topic, $subscribeCallback);
    }

    /**
     * Handle an subscription Event
     *
     * @param $args
     * @param MappingInterface $mapping
     * @throws \Exception
     */
    protected function handleEvent($args, MappingInterface $mapping)
    {
        try {
            //Force clean up before calling the subscribed method
            $this->cleanup();

            $controller     = $this->container->get($mapping->getServiceId());
            $controllerArgs = $this->deserializeArgs($args, $mapping);

            //Call Controller
            call_user_func_array([$controller, $mapping->getMethod()->getName()], $controllerArgs);

            $this->cleanup($controller);

        } catch (\Exception $e) {
            $this->cleanup();
            $message = "Unable to publish to: {$mapping->getAnnotation()->getName()} \n Message:  {$e->getMessage()}";
            $this->container->get('logger')->critical($message);
            throw new \Exception($message);
        }
    }

    /**
     * Create serialization context with settings taken from the controller's annotation
     *
     * @param MappingInterface $mapping
     * @return SerializationContext
     */
    protected function createSerializationContext(MappingInterface $mapping)
    {
        $context = new SerializationContext();

        if ($mapping->getAnnotation()->getSerializerEnableMaxDepthChecks()) {
            $context->enableMaxDepthChecks();
        }

        if ($mapping->getAnnotation()->getSerializerGroups()) {
            $context->setGroups($mapping->getAnnotation()->getSerializerGroups());
        }

        if ($mapping->getAnnotation()->getSerializerSerializeNull()) {
            $context->setSerializeNull(true);
        }

        return $context;
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @param Client $client
     */
    public function setClient(Client $client)
    {
        $this->client = $client;
        $this->client->on('open', [$this, 'onOpen']);
    }


    /**
     * @return mixed
     */
    public function getSession()
    {
        return $this->session;
    }

    /**
     * @return mixed
     */
    public function getTransport()
    {
        return $this->transport;
    }

    /**
     * Deserialize Controller arguments
     *
     * //@todo add ability to configure the deserialization context
     *
     * @param $args
     * @param MappingInterface $mapping
     * @return array|bool
     */
    private function deserializeArgs($args, MappingInterface $mapping)
    {
        try {
            $args = (array)$args;

            if ($this->isAssoc($args)) {
                $args = [$args];
            }

            $deserializedArgs = [];

            if ($mapping->getMethod()->getNumberOfRequiredParameters() > count($args)) {
                throw new \Exception(
                    "Not enough parameters for '{$mapping->getMethod()->class}::{$mapping->getMethod()->getName()}'"
                );
            }

            /* @var $params \ReflectionParameter[] */
            $params = $mapping->getmethod()->getParameters();

            foreach ($args as $key => $arg) {

                $className = null;
                if (isset($params[$key]) && $params[$key]->getClass() && $params[$key]->getClass()->getName()) {
                    if (!$params[$key]->getClass()->isInstantiable()) {

                        throw new \Exception(
                            "Can't deserialize to '{$params[$key]->getClass()->getName()}', because it is not instantiable."
                        );
                    }

                    $className          = $params[$key]->getClass()->getName();
                    $deserializedArgs[] = $this->serializer->deserialize(json_encode($arg), $className, "json");

                } else {
                    $deserializedArgs[] = $arg;
                }

            }

            return $deserializedArgs;

        } catch (\Exception $e) {
            $this->container->get('logger')->addEmergency($e->getMessage());

        }

        return [];
    }

    /**
     * @param $authid
     * @param ContainerInterface $container
     * @return UserInterface
     */
    private function authenticateAuthId($authid, ContainerInterface $container)
    {
        $user = null;

        //Use the global container so every call uses the same instance of the user provider
        $config = $this->container->getParameter('voryx_thruway');

        if ($this->container->has($config['user_provider'])) {
            $user = $this->container->get($config['user_provider'])->loadUserByUsername($authid);
        }

        $user = $user ?: new User($authid, null);
        $this->authenticateUser($user, $container);

        return $user;
    }

    /**
     * @param UserInterface $user
     * @param ContainerInterface $container
     */
    private function authenticateUser(UserInterface $user, ContainerInterface $container)
    {
        $providerKey = 'thruway';
        $token       = new UsernamePasswordToken($user, null, $providerKey, $user->getRoles());

        //Use the controller's container to set the token
        $container->get('security.context')->setToken($token);
    }

    /**
     * @param $controller
     * @param URIClassMapping $mapping
     */
    private function dispatchControllerEvents($controller, URIClassMapping $mapping)
    {
        $request  = new Request();
        $callable = [$controller, $mapping->getMethod()->getName()];
        $event    = new FilterControllerEvent($this, $callable, $request, self::MASTER_REQUEST);
        $this->dispatcher->dispatch(KernelEvents::CONTROLLER, $event);
    }

    /**
     * @return null
     */
    public function getProcessName()
    {
        return $this->processName;
    }

    /**
     * @param null $processName
     */
    public function setProcessName($processName)
    {
        $this->processName = $processName;
    }

    /**
     * @return ResourceMapper
     */
    public function getResourceMapper()
    {
        return $this->resourceMapper;
    }

    /**
     * @return mixed
     */
    public function getProcessInstance()
    {
        return $this->processInstance;
    }

    /**
     * @param mixed $processInstance
     */
    public function setProcessInstance($processInstance)
    {
        $this->processInstance = $processInstance;
    }


    /**
     * @param $arr
     * @return bool
     */
    private function isAssoc($arr)
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }


    /**
     *  Cleanup
     * @param $controller
     */
    private function cleanup($controller = null)
    {
        unset ($controller);

        //Clear out any stuff that doctrine has cached
        if ($this->container->has('doctrine')) {
            if (!$this->container->get('doctrine')->getManager()->isOpen()) {
                $this->container->get('doctrine')->resetManager();
                $config = $this->container->getParameter('voryx_thruway');

                if ($this->container->has($config['user_provider'])) {
                    $this->container->set($config['user_provider'], null);
                }
            }
            $this->container->get('doctrine')->getManager()->clear();
        }


//        //Remove any listeners for the kernel.controller event
//        $listeners = $this->dispatcher->getListeners("kernel.controller");
//        foreach ($listeners as $listener) {
//            $this->dispatcher->removeListener("kernel.controller", $listener);
//        }

    }

    /**
     * //Not tested
     *
     * @param URIClassMapping $mapping
     * @param $action
     */
    public function sendAuthorization(URIClassMapping $mapping, $action)
    {
        $uri   = $mapping->getAnnotation()->getName();
        $roles = $this->extractRoles($mapping);

        foreach ($roles as $role) {

            $this->session->call("add_authorization_rule", [
                [
                    "role"   => $role,
                    "action" => $action,
                    "uri"    => $uri,
                    "allow"  => true
                ]
            ])->then(
                function ($r) {
                    echo "Sent authorization\n";
                },
                function ($msg) {
                    echo "Failed to send authorization\n";
                }
            );
        }
    }

    /**
     * @param URIClassMapping $mapping
     * @return array
     */
    protected function extractRoles(URIClassMapping $mapping)
    {
        $roles = [];

        $securityAnnotation = $mapping->getSecurityAnnotation();

        if ($securityAnnotation) {

            $expression = $securityAnnotation->getExpression();

            preg_match_all("/'(.*?)'/", $expression, $matches);
            $roles = $matches[1];
        }

        return $roles;

    }


    public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true)
    {
        // TODO: Implement handle() method.
    }
}
