<?php declare(strict_types=1);
/**
 * This file is part of AsyncVerimail.
 *
 * Copyright (c) 2020 Balovnev Anton <an43.bal@gmail.com>
 */

namespace App\DB;

use App\Verifier\VerifyStatus;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

class EmailHydrationStrategy implements HydrationStrategyInterface
{
    private ReflectionClass $class;
    /**
     * @var mixed[]
     */
    private array $constructorParams;
    /**
     * @var int[]
     */
    private array $rowFields = [];
    private string $dateFormat = EmailEntityManager::DATE_FORMAT_DB;

    /**
     * PublicFieldsHydrationStrategy constructor.
     *
     * @param ReflectionClass $class
     * @param mixed[]         $constructorParams
     */
    public function __construct(ReflectionClass $class, array $constructorParams = [])
    {
        $this->class = $class;
        $this->constructorParams = $constructorParams;
    }

    /**
     * @param string[] $rowFields
     */
    public function setRowFields(array $rowFields): void
    {
        $this->rowFields = array_flip($rowFields);
    }

    /**
     * {@inheritdoc}
     *
     * @throws Exception
     */
    public function hydrate(array $row): object
    {
        if ($this->rowFields) {
            $row = array_combine(array_keys($this->rowFields), $row);
        }
        if (false === $row) {
            throw new InvalidArgumentException('Cannot hydrate row. Invalid number of fields.');
        }
        $object = $this->class->newInstanceArgs($this->constructorParams);
        foreach ($this->class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            if (!array_key_exists($property->name, $row)) {
                continue;
            }
            if (!$property->hasType() || is_null($row[$property->name])) {
                $object->{$property->name} = $row[$property->name];

                continue;
            }
            /* @var $type ReflectionNamedType */
            $type = $property->getType();
            if ($type->isBuiltin()) {
                settype($row[$property->name], $type->getName());
                $object->{$property->name} = $row[$property->name];

                continue;
            }
            if (is_a($type->getName(), DateTimeInterface::class, true)) {
                $object->{$property->name} = new DateTimeImmutable($row[$property->name]);

                continue;
            }
            if (is_a($type->getName(), VerifyStatus::class, true)) {
                $object->{$property->name} = new VerifyStatus($row[$property->name]);

                continue;
            }
        }

        return $object;
    }

    /**
     * {@inheritdoc}
     */
    public function dehydrate(object $entity): array
    {
        $result = [];
        foreach ($this->class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            if ($this->rowFields && !isset($this->rowFields[$property->name])) {
                continue;
            }
            $value = $entity->{$property->name};
            if (!$property->hasType() || $property->getType()->isBuiltin()) {
                $result[$property->name] = $value;

                continue;
            }
            if ($value instanceof VerifyStatus) {
                $result[$property->name] = (string) $value;
            }
            if ($value instanceof DateTimeInterface) {
                $result[$property->name] = $value->format($this->dateFormat);
            }
        }
        if ($this->rowFields) {
            uksort($result, function ($key1, $key2) {
                return $this->rowFields[$key1] - $this->rowFields[$key2];
            });
        }

        return $result;
    }
}
