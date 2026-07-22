<?php

namespace App\Enums;

enum ContentWorkflowStatus: string
{
    case BriefReady = 'brief_ready';
    case InProgress = 'in_progress';
    case WaitingReview = 'waiting_review';
    case Revision = 'revision';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Uploaded = 'uploaded';
    case Cancelled = 'cancelled';

    public function column(): string { /* map ke nama kolom board */ }
    public function label(): string { /* label tampilan */ }
}