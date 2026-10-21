<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\FeedText;

/**
 * The model's text columns (CLEAN_TEXT) are always saved as clean UTF-8,
 * whichever path writes them — importer, admin form or tinker — so broken
 * characters like "4Ã—4" can't reach a page.
 */
trait CleansText
{
    protected static function bootCleansText(): void
    {
        static::saving(function (self $model) {
            foreach (static::CLEAN_TEXT as $column) {
                if ($model->isDirty($column) && is_string($model->getAttribute($column))) {
                    $model->setAttribute($column, FeedText::clean($model->getAttribute($column)));
                }
            }
        });
    }
}
