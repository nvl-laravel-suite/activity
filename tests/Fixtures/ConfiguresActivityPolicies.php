<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures;

use Nvl\Activity\Services\ActivityPolicyRegistry;
use Nvl\Activity\Support\ActivityEventPolicy;

/** Explicit fixture event catalog; production consumers declare their own narrower policies. */
trait ConfiguresActivityPolicies
{
    protected function configureActivityPolicies(): void
    {
        $registry = app(ActivityPolicyRegistry::class);
        foreach (['delivered', 'action', 'activated', 'activity_logged', 'administrative', 'approval', 'approved', 'archived', 'assign', 'assigned', 'background', 'before', 'binding_required', 'blocked', 'blocker_added', 'blocker_removed', 'cancelled', 'checklist_item_added', 'checklist_item_completed', 'checklist_item_removed', 'checklist_item_reopened', 'checklist_item_updated', 'checklist_reordered', 'commented', 'completed', 'conflict', 'consumer.reference_recorded', 'consumer.semantic_event', 'consumer_event', 'consumer_semantic_event', 'create', 'created', 'critical', 'deactivated', 'delete', 'deleted', 'details_updated', 'disabled', 'enabled', 'follow_up', 'foreign', 'forged', 'general', 'high', 'imported', 'in_progress', 'list', 'low', 'native.reviewed', 'normal', 'old', 'open', 'operation_failed', 'operations', 'parent_linked', 'parent_unlinked', 'project', 'resent', 'restore', 'restored', 'retried', 'review', 'reviewed', 'rewritten', 'rolled_back', 'security_synchronized', 'sent', 'status_changed', 'status_override', 'status_transition', 'synchronized', 'tag_added', 'tag_removed', 'task.created', 'task.updated', 'task_revision_conflict', 'time_entry_added', 'time_entry_removed', 'time_entry_updated', 'timer_started', 'timer_stopped', 'triggered', 'unarchived', 'unassigned', 'unknown', 'update', 'updated', 'urgent', 'verified', 'view', 'viewed'] as $event) {
            $registry->register(new ActivityEventPolicy($event, changeFields: null, metadataFields: null, retentionDays: 1));
        }
    }
}
