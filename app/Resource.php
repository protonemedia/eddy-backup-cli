<?php

namespace App;

class Resource
{
    private $resource;

    public function __construct($resource = null)
    {
        $this->resource = is_resource($resource) ? $resource : fopen('php://stdin', 'r');
    }

    public function get()
    {
        return $this->resource;
    }

    public function __destruct()
    {
        fclose($this->resource);
    }
}
