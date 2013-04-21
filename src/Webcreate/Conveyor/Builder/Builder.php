<?php

/*
 * @author Jeroen Fiege <jeroen@webcreate.nl>
 * @copyright Webcreate (http://webcreate.nl)
 */

namespace Webcreate\Conveyor\Builder;

use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use Webcreate\Conveyor\Event\TaskRunnerEvents;
use Webcreate\Conveyor\Task\Result\ExecuteResult;
use Webcreate\Conveyor\Context;
use Webcreate\Conveyor\Task\Task;
use Webcreate\Conveyor\Event\BuilderEvents;
use Webcreate\Conveyor\IO\IOInterface;
use Webcreate\Conveyor\Repository\Version;
use Webcreate\Conveyor\Task\TaskRunner;

/**
 * @todo remove dependency on Context, have the BuildStage request the files
 * @todo use the TaskRunner
 */
class Builder
{
    protected $builddir;
    protected $io;
    protected $dispatcher;
    //protected $tasks = array();
    protected $context;
    protected $taskrunner;

    /**
     * Constructor.
     *
     * @todo Looks like the $io isn't used/needed here...
     *
     * @param string                   $builddir   destionation path for build
     * @param array                    $tasks
     * @param IOInterface              $io
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct($builddir, array $tasks = array(),
            IOInterface $io = null, EventDispatcherInterface $dispatcher = null
    )
    {
        $this->builddir   = rtrim($builddir, '/');
        //$this->tasks      = $tasks;
        $this->io         = $io;
        $this->dispatcher = $dispatcher;
        $this->taskrunner = new TaskRunner($this->io, new EventDispatcher());
        $this->taskrunner->setTasks($tasks);
    }

    /**
     * Add task to the build process
     *
     * @param Task $task
     * @return $this
     */
    public function addTask(Task $task)
    {
        $this->taskrunner->addTask($task);
        //$this->tasks[] = $task;

        return $this;
    }

    /**
     * Return destination path for build
     *
     * @return string
     */
    public function getBuildDir()
    {
        return $this->builddir;
    }

    public function setContext(Context $context)
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Run added tasks
     *
     * @param string  $target
     * @param Version $version
     */
    public function build($target, Version $version)
    {
        $self = $this;

        $this->dispatch(BuilderEvents::BUILDER_PRE_BUILD);

        $this->taskrunner->getDispatcher()->addListener(
            TaskRunnerEvents::TASKRUNNER_PRE_EXECUTE_TASK,
            function (GenericEvent $event) use ($self) {
                $task = $event->getSubject();
                $t = $event->getArgument('index');
                $total = $event->getArgument('total');

                $self->dispatch(BuilderEvents::BUILDER_PRE_TASK,
                    new GenericEvent($task, array('index' => $t, 'total' => $total))
                );
            }
        );

        $this->taskrunner->getDispatcher()->addListener(
            TaskRunnerEvents::TASKRUNNER_POST_EXECUTE_TASK,
            function (GenericEvent $event) use ($self) {
                $task = $event->getSubject();
                $t = $event->getArgument('index');
                $total = $event->getArgument('total');
                $result = $event->getArgument('result');

                if ($result instanceof ExecuteResult) {
                    $self->applyResultToFilelist($result);
                }

                $self->dispatch(BuilderEvents::BUILDER_POST_TASK,
                    new GenericEvent($task, array('index' => $t, 'total' => $total))
                );
            }
        );

        $tasks = $this->getSupportedTasks($target, $version);

        $this->taskrunner->setTasks($tasks);

        $this->taskrunner->execute($target, $version);

//        foreach ($tasks as $t => $task) {
//            $this->dispatch(BuilderEvents::BUILDER_PRE_TASK,
//                new GenericEvent($task, array('index' => $t, 'total' => $total))
//            );
//
//            $result = $task->execute($target, $version);
//
//            if ($result instanceof ExecuteResult) {
//                $this->applyResultToFilelist($result);
//            }
//
//            $this->dispatch(BuilderEvents::BUILDER_POST_TASK,
//                new GenericEvent($task, array('index' => $t, 'total' => $total))
//            );
//        }

        $this->dispatch(BuilderEvents::BUILDER_POST_BUILD);
    }

    protected function applyResultToFilelist(ExecuteResult $result)
    {
        $filelist = $this->context->getFilelist();

        foreach($result->getDerived() as $pattern) {
            $filelist->add($pattern);
        }

        foreach($result->getRemoved() as $pattern) {
            $filelist->remove($pattern);
        }
    }

    /**
     * Filters the tasks for given target
     *
     * @param  string $target
     * @param \Webcreate\Conveyor\Repository\Version $version
     * @return Task[] task for the specific target
     */
    protected function getSupportedTasks($target, Version $version)
    {
        $tasks = array_filter($this->taskrunner->getTasks(), function($task) use ($target, $version) {
            return (true === $task->supports($target, $version));
        });

        // reindex
        $tasks = array_values($tasks);

        return $tasks;
    }

    /**
     * Dispatch event when a dispatcher is available
     *
     * @param string $eventName
     * @param Event  $event
     */
    protected function dispatch($eventName, Event $event = null)
    {
        if (null !== $this->dispatcher) {
            $this->dispatcher->dispatch($eventName, $event);
        }
    }
}
