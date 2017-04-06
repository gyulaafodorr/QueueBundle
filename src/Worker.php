<?php

namespace IdeasBucket\QueueBundle;

use Psr\SimpleCache\CacheInterface as Cache;
use IdeasBucket\QueueBundle\Event\EventsList;
use IdeasBucket\QueueBundle\Event\JobFailed;
use IdeasBucket\QueueBundle\Exception\ErrorHandler;
use IdeasBucket\QueueBundle\Exception\ManuallyFailedException;
use IdeasBucket\QueueBundle\Exception\MaxAttemptsExceededException;
use IdeasBucket\QueueBundle\Job\JobsInterface;
use IdeasBucket\QueueBundle\Type\QueueInterface;
use Symfony\Component\Debug\Exception\FatalThrowableError;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class Worker
 *
 * @package IdeasBucket\QueueBundle
 */
class Worker
{
    /**
     * The queue manager instance.
     *
     * @var Manager
     */
    protected $manager;

    /**
     * The event dispatcher instance.
     *
     * @var EventDispatcherInterface
     */
    protected $events;

    /**
     * The cache repository implementation.
     *
     * @var Cache
     */
    protected $cache;

    /**
     * The exception handler instance.
     *
     * @var ErrorHandler
     */
    protected $exceptions;

    /**
     * Indicates if the worker is paused.
     *
     * @var bool
     */
    protected $paused = false;

    /**
     * Create a new queue worker.
     *
     * @param Manager                  $manager
     * @param EventDispatcherInterface $events
     * @param ErrorHandler             $exceptions
     */
    public function __construct(Manager $manager, EventDispatcherInterface $events, ErrorHandler $exceptions)
    {
        $this->events = $events;
        $this->manager = $manager;
        $this->exceptions = $exceptions;
    }

    /**
     * Listen to the given queue in a loop.
     *
     * @param  string        $connectionName
     * @param  string        $queue
     * @param  WorkerOptions $options
     */
    public function daemon($connectionName, $queue, WorkerOptions $options)
    {
        $this->listenForSignals();

        $lastRestart = $this->getTimestampOfLastQueueRestart();

        while (true) {

            // First, we will attempt to get the next job off of the queue. We will also
            // register the timeout handler and reset the alarm for this job so it is
            // not stuck in a frozen state forever. Then, we can fire off this job.
            $job = $this->getNextJob($this->manager->connection($connectionName), $queue);

            $this->registerTimeoutHandler($job, $options);

            // If the daemon should run (not in maintenance mode, etc.), then we can run
            // fire off this job for processing. Otherwise, we will need to sleep the
            // worker so no more jobs are processed until they should be processed.
            if ($job && $this->daemonShouldRun($options)) {

                $this->runJob($job, $connectionName, $options);

            } else {

                $this->sleep($options->sleep);
            }

            // Finally, we will check to see if we have exceeded our memory limits or if
            // the queue should restart based on other indications. If so, we'll stop
            // this worker and let whatever is "monitoring" it restart the process.
            if ($this->memoryExceeded($options->memory)) {

                $this->stop(12);

            } elseif ($this->queueShouldRestart($lastRestart)) {

                $this->stop();
            }
        }
    }

    /**
     * @return Manager
     */
    public function getManager()
    {
        return $this->manager;
    }

    /**
     * @param Manager $manager
     *
     * @return Worker
     */
    public function setManager($manager)
    {
        $this->manager = $manager;

        return $this;
    }

    /**
     * Enable async signals for the process.
     */
    protected function listenForSignals()
    {
        if ($this->supportsAsyncSignals()) {

            pcntl_async_signals(true);

            pcntl_signal(SIGUSR2, function () {

                $this->paused = true;
            });

            pcntl_signal(SIGCONT, function () {

                $this->paused = false;
            });
        }
    }

    /**
     * Determine if "async" signals are supported.
     *
     * @return bool
     */
    protected function supportsAsyncSignals()
    {
        return version_compare(PHP_VERSION, '7.1.0') >= 0 && extension_loaded('pcntl');
    }

    /**
     * Get the last queue restart timestamp, or null.
     *
     * @return int|null
     */
    protected function getTimestampOfLastQueueRestart()
    {
        if ($this->cache) {

            return $this->cache->get('ideasbucket_queue:restart');
        }
    }

    /**
     * Get the next job from the queue connection.
     *
     * @param  QueueInterface $connection
     * @param  string         $queue
     *
     * @return JobsInterface|null
     */
    protected function getNextJob($connection, $queue)
    {
        try {

            foreach (explode(',', $queue) as $queue) {

                if (!is_null($job = $connection->pop($queue))) {

                    return $job;
                }
            }

        } catch (\Exception $e) {

            $this->exceptions->report($e);

        } catch (\Throwable $e) {

            $this->exceptions->report(new FatalThrowableError($e));
        }
    }

    /**
     * Register the worker timeout handler (PHP 7.1+).
     *
     * @param  JobsInterface|null $job
     * @param  WorkerOptions      $options
     */
    protected function registerTimeoutHandler($job, WorkerOptions $options)
    {
        if ($options->timeout > 0 && $this->supportsAsyncSignals()) {

            // We will register a signal handler for the alarm signal so that we can kill this
            // process if it is running too long because it has frozen. This uses the async
            // signals supported in recent versions of PHP to accomplish it conveniently.
            pcntl_signal(SIGALRM, function () {

                $this->kill(1);
            });

            pcntl_alarm($this->timeoutForJob($job, $options) + $options->sleep);
        }
    }

    /**
     * Kill the process.
     *
     * @param  int $status
     */
    public function kill($status = 0)
    {
        if (extension_loaded('posix')) {

            posix_kill(getmypid(), SIGKILL);
        }

        exit($status);
    }

    /**
     * Get the appropriate timeout for the given job.
     *
     * @param  JobsInterface|null $job
     * @param  WorkerOptions      $options
     *
     * @return int
     */
    protected function timeoutForJob($job, WorkerOptions $options)
    {
        return $job && !is_null($job->timeout()) ? $job->timeout() : $options->timeout;
    }

    /**
     * Determine if the daemon should process on this iteration.
     *
     * @param  WorkerOptions $options
     *
     * @return bool
     */
    protected function daemonShouldRun(WorkerOptions $options)
    {
        if (($this->manager->isDownForMaintenance() && !$options->force) ||
            $this->paused ||
            $this->until() === false
        ) {

            // If the application is down for maintenance or doesn't want the queues to run
            // we will sleep for one second just in case the developer has it set to not
            // sleep at all. This just prevents CPU from maxing out in this situation.
            $this->sleep(1);

            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    protected function until()
    {
        return $this->events->dispatch(EventsList::LOOPING, new Event\Looping)->isPropagationStopped();
    }

    /**
     * Sleep the script for a given number of seconds.
     *
     * @param  int $seconds
     */
    public function sleep($seconds)
    {
        sleep($seconds);
    }

    /**
     * Process the given job.
     *
     * @param  JobsInterface $job
     * @param  string        $connectionName
     * @param  WorkerOptions $options
     */
    protected function runJob($job, $connectionName, WorkerOptions $options)
    {
        try {

            $this->process($connectionName, $job, $options);

        } catch (\Exception $e) {

            $this->exceptions->report($e);

        } catch (\Throwable $e) {

            $this->exceptions->report(new FatalThrowableError($e));
        }
    }

    /**
     * Process a given job from the queue.
     *
     * @param  string        $connectionName
     * @param  JobsInterface $job
     * @param  WorkerOptions $options
     *
     * @throws \Throwable
     */
    public function process($connectionName, $job, WorkerOptions $options)
    {
        try {

            // First we will raise the before job event and determine if the job has already ran
            // over the its maximum attempt limit, which could primarily happen if the job is
            // continually timing out and not actually throwing any exceptions from itself.
            $this->raiseBeforeJobEvent($connectionName, $job);

            $this->markJobAsFailedIfAlreadyExceedsMaxAttempts($connectionName, $job, (int)$options->maxTries);

            // Here we will fire off the job and let it process. We will catch any exceptions so
            // they can be reported to the developers logs, etc. Once the job is finished the
            // proper events will be fired to let any listeners know this job has finished.
            $job->fire();

            $this->raiseAfterJobEvent($connectionName, $job);

        } catch (\Exception $e) {

            $this->handleJobException($connectionName, $job, $options, $e);

        } catch (\Throwable $e) {

            $this->handleJobException($connectionName, $job, $options, new FatalThrowableError($e));
        }
    }

    /**
     * Raise the before queue job event.
     *
     * @param  string        $connectionName
     * @param  JobsInterface $job
     */
    protected function raiseBeforeJobEvent($connectionName, $job)
    {
        $this->events->dispatch(EventsList::JOB_PROCESSING, new Event\JobProcessing($connectionName, $job));
    }

    /**
     * Mark the given job as failed if it has exceeded the maximum allowed attempts.
     *
     * This will likely be because the job previously exceeded a timeout.
     *
     * @param  string                          $connectionName
     * @param  JobsInterface $job
     * @param  int                             $maxTries
     */
    protected function markJobAsFailedIfAlreadyExceedsMaxAttempts($connectionName, $job, $maxTries)
    {
        $maxTries = !is_null($job->maxTries()) ? $job->maxTries() : $maxTries;

        if ($maxTries === 0 || $job->attempts() <= $maxTries) {

            return;
        }

        $exception = new MaxAttemptsExceededException;

        $this->failJob($connectionName, $job, $exception);

        throw $exception;
    }

    /**
     * Mark the given job as failed and raise the relevant event.
     *
     * @param  string        $connectionName
     * @param  JobsInterface $job
     * @param  \Exception    $e
     */
    protected function failJob($connectionName, $job, $e)
    {
        $job->markAsFailed();

        if ($job->isDeleted()) {

            return;
        }

        try {
            // If the job has failed, we will delete it, call the "failed" method and then call
            // an event indicating the job has failed so it can be logged if needed. This is
            // to allow every developer to better keep monitor of their failed queue jobs.
            $job->delete();
            $job->failed($e);

        } finally {

            $this->events->dispatch(EventsList::JOB_FAILED, new JobFailed($connectionName, $job, $e ?: new ManuallyFailedException));
        }
    }

    /**
     * Raise the after queue job event.
     *
     * @param  string        $connectionName
     * @param  JobsInterface $job
     */
    protected function raiseAfterJobEvent($connectionName, $job)
    {
        $this->events->dispatch(EventsList::JOB_PROCESSED, new Event\JobProcessed($connectionName, $job));
    }

    /**
     * Handle an exception that occurred while the job was running.
     *
     * @param  string        $connectionName
     * @param  JobsInterface $job
     * @param  WorkerOptions $options
     * @param  \Exception    $e
     *
     * @throws \Exception
     */
    protected function handleJobException($connectionName, $job, WorkerOptions $options, $e)
    {
        try {

            // First, we will go ahead and mark the job as failed if it will exceed the maximum
            // attempts it is allowed to run the next time we process it. If so we will just
            // go ahead and mark it as failed now so we do not have to release this again.
            $this->markJobAsFailedIfWillExceedMaxAttempts($connectionName, $job, (int)$options->maxTries, $e);

            $this->raiseExceptionOccurredJobEvent($connectionName, $job, $e);

        } finally {

            // If we catch an exception, we will attempt to release the job back onto the queue
            // so it is not lost entirely. This'll let the job be retried at a later time by
            // another listener (or this same one). We will re-throw this exception after.
            if (!$job->isDeleted()) {

                $job->release($options->delay);
            }
        }

        throw $e;
    }

    /**
     * Mark the given job as failed if it has exceeded the maximum allowed attempts.
     *
     * @param  string        $connectionName
     * @param  JobsInterface $job
     * @param  int           $maxTries
     * @param  \Exception    $e
     */
    protected function markJobAsFailedIfWillExceedMaxAttempts($connectionName, $job, $maxTries, $e)
    {
        $maxTries = !is_null($job->maxTries()) ? $job->maxTries() : $maxTries;

        if ($maxTries > 0 && $job->attempts() >= $maxTries) {

            $this->failJob($connectionName, $job, $e);
        }
    }

    /**
     * Raise the exception occurred queue job event.
     *
     * @param  string        $connectionName
     * @param  JobsInterface $job
     * @param  \Exception    $e
     */
    protected function raiseExceptionOccurredJobEvent($connectionName, $job, $e)
    {
        $this->events->dispatch(EventsList::JOB_EXCEPTION_OCCURRED, new Event\JobExceptionOccurred($connectionName, $job, $e));
    }

    /**
     * Determine if the memory limit has been exceeded.
     *
     * @param  int $memoryLimit
     *
     * @return bool
     */
    public function memoryExceeded($memoryLimit)
    {
        return (memory_get_usage() / 1024 / 1024) >= $memoryLimit;
    }

    /**
     * Stop listening and bail out of the script.
     *
     * @param  int $status
     */
    public function stop($status = 0)
    {
        $this->events->dispatch(EventsList::WORKER_STOPPING, new Event\WorkerStopping);

        exit($status);
    }

    /**
     * Determine if the queue worker should restart.
     *
     * @param  int|null $lastRestart
     *
     * @return bool
     */
    protected function queueShouldRestart($lastRestart)
    {
        return $this->getTimestampOfLastQueueRestart() != $lastRestart;
    }

    /**
     * Process the next job on the queue.
     *
     * @param  string        $connectionName
     * @param  string        $queue
     * @param  WorkerOptions $options
     */
    public function runNextJob($connectionName, $queue, WorkerOptions $options)
    {
        $job = $this->getNextJob($this->manager->connection($connectionName), $queue);

        // If we're able to pull a job off of the stack, we will process it and then return
        // from this method. If there is no job on the queue, we will "sleep" the worker
        // for the specified number of seconds, then keep processing jobs after sleep.
        if ($job) {

            $this->runJob($job, $connectionName, $options);
        }

        $this->sleep($options->sleep);
    }

    /**
     * Set the cache repository implementation.
     *
     * @param  Cache $cache
     */
    public function setCache(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Raise the failed queue job event.
     *
     * @param  string        $connectionName
     * @param  JobsInterface $job
     * @param  \Exception    $e
     */
    protected function raiseFailedJobEvent($connectionName, $job, $e)
    {
        $this->events->dispatch(EventsList::JOB_FAILED, new Event\JobFailed($connectionName, $job, $e));
    }
}