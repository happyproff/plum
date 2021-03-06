<?php

/**
 * This file is part of plumphp/plum.
 *
 * (c) Florian Eckerstorfer <florian@eckerstorfer.co>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plum\Plum;

use Cocur\Vale\Vale;
use Plum\Plum\Converter\ConverterInterface;
use Plum\Plum\Filter\FilterInterface;
use Plum\Plum\Reader\ReaderInterface;
use Plum\Plum\Writer\WriterInterface;

/**
 * Workflow
 *
 * @package   Plum\Plum
 * @author    Florian Eckerstorfer <florian@eckerstorfer.co>
 * @copyright 2014-2015 Florian Eckerstorfer
 */
class Workflow
{
    const PIPELINE_TYPE_FILTER          = 1;
    const PIPELINE_TYPE_CONVERTER       = 2;
    const PIPELINE_TYPE_WRITER          = 3;
    const PIPELINE_TYPE_VALUE_FILTER    = 4;
    const PIPELINE_TYPE_VALUE_CONVERTER = 5;

    const APPEND  = 1;
    const PREPEND = 2;

    /** @var PipelineInterface[][] */
    private $pipeline = [];

    /**
     * @param string|null $type
     *
     * @return PipelineInterface[]
     */
    public function getPipeline($type = null)
    {
        $pipeline = [];

        foreach ($this->pipeline as $element) {
            if ($type === null || $element[0] === $type) {
                $pipeline[] = $element[1];
            }
        }

        return $pipeline;
    }

    /**
     * @param <string,PipelineInterface> $element
     * @param int                        $position
     *
     * @return Workflow
     */
    protected function add($element, $position = self::APPEND)
    {
        if ($position === self::PREPEND) {
            array_unshift($this->pipeline, $element);
        } else {
            $this->pipeline[] = $element;
        }

        return $this;
    }

    /**
     * @param FilterInterface $filter
     * @param int             $position
     *
     * @return Workflow
     */
    public function addFilter(FilterInterface $filter, $position = self::APPEND)
    {
        return $this->add([self::PIPELINE_TYPE_FILTER, $filter], $position);
    }

    /**
     * @return FilterInterface[]
     */
    public function getFilters()
    {
        return $this->getPipeline(self::PIPELINE_TYPE_FILTER);
    }

    /**
     * @param string|array    $field
     * @param FilterInterface $filter
     * @param int             $position
     *
     * @return Workflow
     */
    public function addValueFilter($field, FilterInterface $filter, $position = self::APPEND)
    {
        return $this->add([self::PIPELINE_TYPE_VALUE_FILTER, $filter, $field], $position);
    }

    /**
     * @return FilterInterface[]
     */
    public function getValueFilters()
    {
        return $this->getPipeline(self::PIPELINE_TYPE_VALUE_FILTER);
    }

    /**
     * @param ConverterInterface   $converter
     * @param FilterInterface|null $filter
     * @param int                  $position
     *
     * @return Workflow $this
     */
    public function addConverter(
        ConverterInterface $converter,
        FilterInterface $filter = null,
        $position = self::APPEND
    ) {
        return $this->add([self::PIPELINE_TYPE_CONVERTER, $converter, $filter], $position);
    }

    /**
     * @return ConverterInterface[]
     */
    public function getConverters()
    {
        return $this->getPipeline(self::PIPELINE_TYPE_CONVERTER);
    }

    /**
     * @param string|array       $field
     * @param ConverterInterface $converter
     * @param FilterInterface    $filter
     * @param int                $position
     *
     * @return Workflow
     */
    public function addValueConverter(
        $field,
        ConverterInterface $converter,
        FilterInterface $filter = null,
        $position = self::APPEND
    ) {
        return $this->add([self::PIPELINE_TYPE_VALUE_CONVERTER, $converter, $filter, $field], $position);
    }

    /**
     * @return ConverterInterface[]
     */
    public function getValueConverters()
    {
        return $this->getPipeline(self::PIPELINE_TYPE_VALUE_CONVERTER);
    }

    /**
     * @param WriterInterface      $writer
     * @param FilterInterface|null $filter
     * @param int                  $position
     *
     * @return Workflow
     */
    public function addWriter(WriterInterface $writer, FilterInterface $filter = null, $position = self::APPEND)
    {
        return $this->add([self::PIPELINE_TYPE_WRITER, $writer, $filter], $position);
    }

    /**
     * @return WriterInterface[]
     */
    public function getWriters()
    {
        return $this->getPipeline(self::PIPELINE_TYPE_WRITER);
    }

    /**
     * @param ReaderInterface $reader
     *
     * @return Result
     */
    public function process(ReaderInterface $reader)
    {
        $result = new Result();

        $this->prepareWriters($this->getWriters());

        foreach ($reader as $item) {
            $result->incReadCount();
            try {
                $this->processItem($item, $result);
            } catch (\Exception $e) {
                $result->addException($e);
            }
        }

        $this->finishWriters($this->getWriters());

        return $result;
    }

    /**
     * @param WriterInterface[] $writers
     *
     * @return void
     */
    protected function prepareWriters($writers)
    {
        foreach ($writers as $writer) {
            $writer->prepare();
        }
    }

    /**
     * @param WriterInterface[] $writers
     *
     * @return void
     */
    protected function finishWriters($writers)
    {
        foreach ($writers as $writer) {
            $writer->finish();
        }
    }

    /**
     * @param mixed  $item
     * @param Result $result
     *
     * @return void
     */
    protected function processItem($item, Result $result)
    {
        $written = false;

        foreach ($this->pipeline as $element) {
            if ($element[0] === self::PIPELINE_TYPE_FILTER) {
                if ($element[1]->filter($item) === false) {
                    return;
                }
            } else if ($element[0] === self::PIPELINE_TYPE_VALUE_FILTER) {
                if ($element[1]->filter(Vale::get($item, $element[2])) === false) {
                    return;
                }
            } else if ($element[0] === self::PIPELINE_TYPE_CONVERTER) {
                $item = $this->convertItem($item, $element[1], $element[2]);
            } else if ($element[0] === self::PIPELINE_TYPE_VALUE_CONVERTER) {
                $item = $this->convertItemValue($item, $element[3], $element[1], $element[2]);
            } else if ($element[0] === self::PIPELINE_TYPE_WRITER) {
                if ($this->writeItem($item, $element[1], $element[2]) === true) {
                    $result->incWriteCount();
                    $written = true;
                }
            }
        }

        if ($written === true) {
            $result->incItemWriteCount();
        }
    }

    /**
     * Applies the given converter to the given item either if no filter is given or if the filter returns `true`.
     *
     * @param mixed                $item
     * @param ConverterInterface   $converter
     * @param FilterInterface|null $filter
     *
     * @return mixed
     */
    protected function convertItem($item, ConverterInterface $converter, FilterInterface $filter = null)
    {
        if ($filter === null || $filter->filter($item) === true) {
            return $converter->convert($item);
        }

        return $item;
    }

    /**
     * Applies the given converter to the given field in the given item if no filter is given or if the filters returns
     * `true` for the field.
     *
     * @param mixed              $item
     * @param string|array       $field
     * @param ConverterInterface $converter
     * @param FilterInterface    $filter
     *
     * @return mixed
     */
    protected function convertItemValue($item, $field, ConverterInterface $converter, FilterInterface $filter = null)
    {
        $value = Vale::get($item, $field);
        if ($filter === null || $filter->filter($value) === true) {
            $item = Vale::set($item, $field, $converter->convert($value));
        }

        return $item;
    }

    /**
     * Writes the given item to the given writer if the no filter is given or the filter returns `true`.
     *
     * @param mixed                $item
     * @param WriterInterface      $writer
     * @param FilterInterface|null $filter
     *
     * @return bool `true` if the item has been written, `false` if not.
     */
    protected function writeItem($item, WriterInterface $writer, FilterInterface $filter = null)
    {
        if ($filter === null || $filter->filter($item) === true) {
            $writer->writeItem($item);

            return true;
        }

        return false;
    }
}
