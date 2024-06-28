<?php

declare(strict_types=1);

namespace JtlWooCommerceConnector\Event;

use Symfony\Contracts\EventDispatcher\Event;

class HandleDeleteEvent extends Event
{
    public const EVENT_NAME = 'connector.handle.delete';

    protected $result;
    protected string $controller;
    protected $entities;

    public function __construct($controller, $entities)
    {
        $this->controller = $controller;
        $this->entities   = $entities;
    }

    /**
     * @return string
     */
    public function getController(): string
    {
        return $this->controller;
    }

    public function getEntities()
    {
        return $this->entities;
    }

    public function getResult()
    {
        return $this->result;
    }

    /**
     * @param $result
     */
    public function setResult($result): static
    {
        $this->result = $result;
        return $this;
    }
}
