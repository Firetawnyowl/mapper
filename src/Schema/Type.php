<?php

namespace Tarantool\Mapper\Schema;

use Tarantool\Mapper\Contracts;
use LogicException;

class Type implements Contracts\Type
{
    protected $properties = [];
    protected $references = [];
    protected $manager;
    protected $name;

    public function __construct(Contracts\Manager $manager, $name,
                                array $properties = null, array $references = null)
    {
        $this->manager = $manager;
        $this->name = $name;

        if ($name == 'mapping') {
            $properties = ['id', 'space', 'line', 'property'];
        }

        if ($properties) {
            $this->properties = $properties;
        }

        if ($references) {
            foreach ($references as $reference) {
                $this->references[$reference->property] = $reference;
            }
        }
    }

    public function getSpace()
    {
        return $this->getManager()->getClient()->getSpace($this->name);
    }

    public function getManager()
    {
        return $this->manager;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getMapping()
    {
        return $this->properties;
    }

    public function addIndex($properties, array $arguments = null)
    {
        $properties = (array) $properties;
        foreach ($properties as $property) {
            if (!$this->hasProperty($property)) {
                throw new LogicException("Unknown property $property for ".$this->name);
            }
        }

        $schema = $this->manager->getSchema();

        sort($properties);
        $indexName = implode('_', $properties);

        if ($schema->hasIndex($this->getName(), $indexName)) {
            throw new LogicException("Index $indexName already exists!");
        }
        if (!$arguments) {
            $arguments = [];
        }

        if (!array_key_exists('parts', $arguments) || !count($arguments['parts'])) {
            $arguments['parts'] = [];
            foreach ($this->getMapping() as $index => $name) {
                if (in_array($name, $properties)) {
                    $arguments['parts'][] = $index + 1;
                    $arguments['parts'][] = $name == 'id' ? 'NUM' : 'STR';
                }
            }
        }

        $schema->createIndex($this->getName(), $indexName, $arguments);

        return $this;
    }

    /**
     * @param $property name
     *
     * @return Type
     */
    public function addProperty($first)
    {
        $properties = is_array($first) ? $first : func_get_args();

        foreach ($properties as $property) {
            if ($this->hasProperty($property)) {
                throw new LogicException("Duplicate property $property");
            }

            $this->manager->make('mapping', [
                'space' => $this->name,
                'line' => count($this->properties),
                'property' => $property,
            ]);

            $this->properties[] = $property;
        }

        return $this;
    }

    public function hasProperty($name)
    {
        return in_array($name, $this->properties);
    }

    public function reference(Contracts\Type $foreign, $property = null)
    {
        if (!$property) {
            $property = $foreign->getName();
        }

        $this->addProperty($property);

        $this->references[$property] = $this->manager->make('reference', [
            'space' => $this->name,
            'property' => $property,
            'type' => $foreign->getName(),
        ]);

        return $this;
    }

    public function encode($input)
    {
        $output = [];
        foreach ($this->getMapping() as $index => $name) {
            if (array_key_exists($name, $input)) {
                $value = $input[$name];
                if (array_key_exists($name, $this->references)) {
                    $value = $value->getId();
                }
                $output[$index] = $value;
            }
        }

        return $output;
    }

    public function decode($input)
    {
        $output = [];
        foreach ($this->getMapping() as $index => $name) {
            if (array_key_exists($index, $input)) {
                $output[$name] = $input[$index];
                if (array_key_exists($name, $this->references)) {
                    $manager = $this->getManager();
                    $type = $this->references[$name]->type;
                    $id = $output[$name];
                    $output[$name] = function () use ($manager, $type, $id) {
                        return $manager->get($type)->find($id);
                    };
                }
            }
        }

        return $output;
    }
}
