<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;

class ContentItemController extends Controller
{
    public function show(ContentItem $contentItem)
    {
        $contentItem->load([
            'client',
            'contentType',
            'platform',
            'workflow.currentPic',
            'assignments.user',
            'statusLogs.changedBy',
            'revisions.requestedBy',
            'publications.platform',
            'publications.publishedBy',
            'delayRiskScores' => fn ($q) => $q->latest()->limit(5),
            'contentBriefDraft.takeByUser',
        ]);

        return view('content-items.show', compact('contentItem'));
    }
}
