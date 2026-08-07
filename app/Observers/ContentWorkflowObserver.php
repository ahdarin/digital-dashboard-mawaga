<?php

namespace App\Observers;

use App\Models\ContentWorkflow;
use App\Services\DelayRiskPredictionService;

class ContentWorkflowObserver
{
    public function created(ContentWorkflow $workflow): void
    {
        if ($workflow->current_status === 'brief_ready') {
            app(DelayRiskPredictionService::class)->predictForItems([$workflow->content_item_id]);
        }
    }

    public function updated(ContentWorkflow $workflow): void
    {
        if ($workflow->wasChanged('current_status') && $workflow->current_status === 'brief_ready') {
            app(DelayRiskPredictionService::class)->predictForItems([$workflow->content_item_id]);
        }
    }
}