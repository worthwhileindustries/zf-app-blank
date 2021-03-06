<?php

namespace Test\Connector;

use Codeception\Lib\Connector\ZF2\PersistentServiceManager;
use Symfony\Component\BrowserKit\Client;
use Symfony\Component\BrowserKit\Request;
use Symfony\Component\BrowserKit\Response;
use Zend\Http\Request as HttpRequest;
use Zend\Http\Headers as HttpHeaders;
use Zend\Mvc\Application;
use Zend\Stdlib\Parameters;
use Zend\Uri\Http as HttpUri;
use Symfony\Component\BrowserKit\Request as BrowserKitRequest;

class ZF3Connector extends Client
{
    /**
     * @var \Zend\Mvc\ApplicationInterface
     */
    protected $application;

    /**
     * @var array
     */
    protected $applicationConfig;

    /**
     * @var  \Zend\Http\PhpEnvironment\Request
     */
    protected $zendRequest;

    /**
     * @var PersistentServiceManager
     */
    private $persistentServiceManager;

    /**
     * @param array $applicationConfig
     */
    public function setApplicationConfig($applicationConfig)
    {
        $this->applicationConfig = $applicationConfig;
        $this->createApplication();
    }

    /**
     * @param Request $request
     *
     * @return Response
     * @throws \Exception
     */
    public function doRequest($request)
    {
        $this->createApplication();
        $zendRequest = $this->application->getRequest();

        $uri = new HttpUri($request->getUri());
        $queryString = $uri->getQuery();
        $method = strtoupper($request->getMethod());

        $zendRequest->setCookies(new Parameters($request->getCookies()));

        $query = [];
        $post = [];
        $content = $request->getContent();
        if ($queryString) {
            parse_str($queryString, $query);
        }

        if ($method !== HttpRequest::METHOD_GET) {
            $post = $request->getParameters();
        }

        $zendRequest->setQuery(new Parameters($query));
        $zendRequest->setPost(new Parameters($post));
        $zendRequest->setFiles(new Parameters($request->getFiles()));
        $zendRequest->setContent($content);
        $zendRequest->setMethod($method);
        $zendRequest->setUri($uri);
        $requestUri = $uri->getPath();
        if (!empty($queryString)) {
            $requestUri .= '?' . $queryString;
        }

        $zendRequest->setRequestUri($requestUri);

        $zendRequest->setHeaders($this->extractHeaders($request));
        $this->application->run();

        // get the response *after* the application has run, because other ZF
        //     libraries like API Agility may *replace* the application's response
        //
        $zendResponse = $this->application->getResponse();

        $this->zendRequest = $zendRequest;

        $exception = $this->application->getMvcEvent()->getParam('exception');
        if ($exception instanceof \Exception) {
            throw $exception;
        }

        $response = new Response(
            $zendResponse->getBody(),
            $zendResponse->getStatusCode(),
            $zendResponse->getHeaders()->toArray()
        );

        return $response;
    }

    /**
     * @return \Zend\Http\PhpEnvironment\Request
     */
    public function getZendRequest()
    {
        return $this->zendRequest;
    }

    private function extractHeaders(BrowserKitRequest $request)
    {
        $headers = [];
        $server = $request->getServer();

        $contentHeaders = array('Content-Length' => true, 'Content-Md5' => true, 'Content-Type' => true);
        foreach ($server as $header => $val) {
            $header = implode('-', array_map('ucfirst', explode('-', strtolower(str_replace('_', '-', $header)))));

            if (strpos($header, 'Http-') === 0) {
                $headers[substr($header, 5)] = $val;
            } elseif (isset($contentHeaders[$header])) {
                $headers[$header] = $val;
            }
        }
        $zendHeaders = new HttpHeaders();
        $zendHeaders->addHeaders($headers);
        return $zendHeaders;
    }

    public function grabServiceFromContainer($service)
    {
        $serviceManager = $this->application->getServiceManager();
        if (!$serviceManager->has($service)) {
            throw new \PHPUnit_Framework_AssertionFailedError("Service $service is not available in container");
        }
        return $serviceManager->get($service);
    }

    public function addServiceToContainer($name, $service)
    {
        /**
         * @var \Zend\ServiceManager\ServiceManager $sm
         */
        $sm = $this->application->getServiceManager();
        $sm->setAllowOverride(true);
        $sm->setService($name, $service);
        $sm->setAllowOverride(false);
    }

    private function createApplication()
    {
        $this->application = Application::init($this->applicationConfig);
        $serviceManager = $this->application->getServiceManager();
        $sendResponseListener = $serviceManager->get('SendResponseListener');
        $events = $this->application->getEventManager();
        $events->detach([$sendResponseListener, 'sendResponse']);
    }
}
