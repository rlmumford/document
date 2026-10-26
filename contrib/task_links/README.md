# Document-related work

Enable `document_task_links` to add **Related tasks** to document pages and
**Related documents** to the task workspace. The integration reads existing
relationships; it does not change task execution or document approval.

The `document_related_tasks` View lists distinct active and completed tasks,
with task access and consumer organization restrictions applied before paging.
Document access is checked before running the View. Reverse links check both
task and document access. Source fields, checklist action state and outcome
fields must be viewable before their relationship details are displayed.

## Existing relationships

- **Context** identifies an ordinary document-reference field on a task or a
  declared document reference in its typed `context` values.
- **Outcome** identifies a document in a checklist item's saved `outcomes`.
  The item's existing `checklist` reference determines the task. The UI shows
  the item title/name and the outcome label/name.

An outcome can refer to an existing reviewed document, a newly created document,
or any document returned by a handler. It does not imply document creation,
approval or successful handling. Multiple references produce one task row with
all visible relationship labels. Completing a task preserves its saved links.
Removing a reference removes that relationship; this module stores no separate
link history, observation timestamps or document revision claims.

Ordinary entity-reference fields are queried directly. Nested typed references
use the optional shared `typed_reference_index` module because their serialized
values cannot be reverse-queried reliably in SQL. Its index is disposable and
rebuildable from saved values; it contains neither task host IDs nor workflow
semantics. Relationship labels are always read from the source entities.

## Consumer extension points

- `hook_task_ui_task_summary_alter(&$build, $task)` extends the read-only summary
  on canonical and inline workspaces. Board actions refresh it without replacing
  checklist forms. This module adds related documents there.
- `hook_document_task_links_compatible_alter(&$allowed, $task, $document)` adds
  relationship constraints during rendering. Combine denials with the existing
  result, never overwrite a denial with an allow.
- `hook_document_task_links_query_alter($query, $document)` adds equivalent SQL
  conditions before pagination. The task alias is `related_task`. Keep these
  conditions consistent with the compatibility hook.

CounselKit's `ck_organization_task` supplies organization constraints and validates
nested typed contexts/outcomes when they are saved. The generic index itself
never grants entity access.
