<?php

namespace Marcz\Swiftype\Jobs;

use AbstractQueuedJob;
use QueuedJob;
use File;
use SS_Datetime;
use Config as FileConfig;
use Marcz\Swiftype\Processor\SwiftExporter;
use Marcz\Swiftype\SwiftypeClient;
use Exception;
use Marcz\Search\Config;

class CrawlBulkExport extends AbstractQueuedJob implements QueuedJob
{
    protected $bulkArray = [];
    protected $client;

    /**
     * Methods that corresponds to the chronological steps for this job.
     * All methods must return true to signal successful process
     *
     * @var array
     */
    protected $definedSteps = [
    ];

    /**
     * @param string $className
     * @param int $offset
     */
    public function __construct($indexName = null, $className = null, $offset = 0)
    {
        $this->totalSteps  = count($this->definedSteps);
        $this->currentStep = 0;
        $this->indexName   = $indexName;
        $this->className   = $className;
        $this->offset      = (int) $offset;
        $this->fileId      = 0;
        $this->bulk        = [];
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        return 'Json document export: "' . $this->className . '" starting at ' . $this->offset;
    }

    /**
     * @return string
     */
    public function getJobType()
    {
        return QueuedJob::QUEUED;
    }

    public function process()
    {
        if (!$this->className) {
            throw new Exception('Missing className defined on the constructor');
        }

        if ($this->definedSteps && !isset($this->definedSteps[$this->currentStep])) {
            throw new Exception('User error, unknown step defined.');
        }

        $this->addMessage('Todo: Implement bulk crawling feature.');
        $this->isComplete = true;
    }


    /**
     * Called when the job is determined to be 'complete'
     * Clean-up object properties
     */
    public function afterComplete()
    {
        $this->indexName = null;
        $this->className = null;
        $this->offset    = 0;
        $this->fileId    = 0;
        $this->bulk      = [];
    }
}