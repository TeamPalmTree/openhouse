<?php

abstract class Module {

    function __construct($configuration)
    {
        $this->configuration = $configuration;
    }

    protected function log($message) {
        echo date('Y-m-d H:i:s') . ' ' . $message . "\n";
    }

    abstract function initialize();
    abstract function entered();
    abstract function occupied();
    abstract function vacant();

}