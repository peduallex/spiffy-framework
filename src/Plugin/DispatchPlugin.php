<?php

namespace Spiffy\Framework\Plugin;

use Spiffy\Event\Manager;
use Spiffy\Event\Plugin;
use Spiffy\Framework\Action\DispatchExceptionAction;
use Spiffy\Framework\Action\DispatchInvalidAction;
use Spiffy\Framework\Action\DispatchInvalidResultAction;
use Spiffy\Framework\Application;
use Spiffy\Framework\ApplicationEvent;
use Spiffy\Route\RouteMatch;
use Spiffy\View\Model;
use Spiffy\View\ViewModel;
use Symfony\Component\HttpFoundation\Response;

final class DispatchPlugin implements Plugin
{
    /**
     * {@inheritDoc}
     */
    public function plug(Manager $events)
    {
        $events->on(Application::EVENT_DISPATCH, [$this, 'dispatch']);
        $events->on(Application::EVENT_DISPATCH, [$this, 'createModelFromArray'], -900);
        $events->on(Application::EVENT_DISPATCH, [$this, 'createModelFromNull'], -900);
        $events->on(Application::EVENT_DISPATCH, [$this, 'handleDispatchInvalidResult'], -1000);

        $events->on(Application::EVENT_DISPATCH_ERROR, [$this, 'handleDispatchInvalid']);
        $events->on(Application::EVENT_DISPATCH_ERROR, [$this, 'handleDispatchException']);
    }

    /**
     * @param \Spiffy\Framework\ApplicationEvent $e
     */
    public function dispatch(ApplicationEvent $e)
    {
        if ($e->getDispatchResult()) {
            return;
        }

        $match = $e->getRouteMatch();
        if (!$match instanceof RouteMatch) {
            return;
        }

        $app = $e->getApplication();
        $i = $app->getInjector();
        $action = $match->get('action');

        /** @var \Spiffy\Dispatch\Dispatcher $d */
        $d = $i->nvoke('Dispatcher');

        $dispatchable = $d->has($action) || (is_string($action) && (class_exists($action) || $i->has($action)));
        if (!$dispatchable) {
            $e->setError(Application::ERROR_DISPATCH_INVALID);
            $e->setType(Application::EVENT_DISPATCH_ERROR);
            $e->set('action', $action);
            $e->getApplication()->events()->fire($e);

            $this->finish($e);
            return;
        }

        try {
            $match->set('__dispatcher', $d);
            $match->set('__event', $e);
            $match->set('__router', $i->nvoke('Router'));

            $e->setDispatchResult($d->ispatch($action, $match->getParams()));
            $this->finish($e);
        } catch (\Exception $ex) {
            $e->setError(Application::ERROR_DISPATCH_EXCEPTION);
            $e->setType(Application::EVENT_DISPATCH_ERROR);
            $e->set('exception', $ex);
            $e->getApplication()->events()->fire($e);
            $this->finish($e);
        }
    }

    /**
     * @param ApplicationEvent $e
     */
    public function createModelFromArray(ApplicationEvent $e)
    {
        $result = $e->getDispatchResult();
        if (!is_array($result) || $e->getError()) {
            return;
        }
        $e->setModel(new ViewModel($result));
    }

    /**
     * @param ApplicationEvent $e
     */
    public function createModelFromNull(ApplicationEvent $e)
    {
        $result = $e->getDispatchResult();
        if (null !== $result || $e->getError()) {
            return;
        }
        $e->setModel(new ViewModel());
    }

    /**
     * @param ApplicationEvent $e
     */
    public function handleDispatchInvalidResult(ApplicationEvent $e)
    {
        $result = $e->getDispatchResult();

        // generally created from arrays or null values via other events
        if ($e->getModel()) {
            return;
        }

        if ($result instanceof Model) {
            return;
        }

        // dispatch returned response for short-circuit
        if ($result instanceof Response) {
            return;
        }

        $i = $e->getApplication()->getInjector();
        $action = new DispatchInvalidResultAction($i->nvoke('ViewManager'));

        $response = $e->getResponse();
        $response->setStatusCode(500);

        $e->setDispatchResult($action($e->getDispatchResult()));
        $this->finish($e);
    }

    /**
     * @param ApplicationEvent $e
     * @return null|ViewModel
     */
    public function handleDispatchInvalid(ApplicationEvent $e)
    {
        if ($e->getError() !== Application::ERROR_DISPATCH_INVALID) {
            return;
        }

        $app = $e->getApplication();
        $i = $app->getInjector();
        $action = new DispatchInvalidAction($i->nvoke('ViewManager'), $app->getRequest());

        $response = $e->getResponse();
        $response->setStatusCode(404);

        $e->setDispatchResult($action($e->get('action')));
    }

    /**
     * @param ApplicationEvent $e
     * @return null|ViewModel
     */
    public function handleDispatchException(ApplicationEvent $e)
    {
        if ($e->getError() !== Application::ERROR_DISPATCH_EXCEPTION) {
            return;
        }

        $i = $e->getApplication()->getInjector();
        $action = new DispatchExceptionAction($i->nvoke('ViewManager'));

        $response = $e->getResponse();
        $response->setStatusCode(500);

        $model = $action($e->get('exception'));

        $e->setModel($model);
        $e->setDispatchResult($model);
    }

    /**
     * @param ApplicationEvent $e
     */
    private function finish(ApplicationEvent $e)
    {
        $result = $e->getDispatchResult();

        if ($result instanceof Response) {
            $e->setResponse($result);
        } elseif ($result instanceof Model) {
            $e->setModel($result);
        }
    }
}
