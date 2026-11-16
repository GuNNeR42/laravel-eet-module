<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Enums\Concerns;

/**
 * Translated label of an enum case (R58).
 */
trait HasLabel
{
    abstract protected static function translationGroup(): string;

    public function label(): string
    {
        $label = trans('laravel-eet-module::messages.'.static::translationGroup().'.'.$this->name);

        return is_string($label) ? $label : $this->name;
    }
}
