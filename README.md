# Document

A document: one file people treat as a unit, what it is, what state it is in,
and what has been worked out about it.

## `file` and `files`

Files arrive in **`files`**, as they were given to us. Where several make up one
document — six photographs of a bank statement, a covering letter and a CV —
they are composed into **`file`**, which is the single readable thing anyone
actually opens.

The originals stay. A composition can be wrong, a page can be missing, and the
only way to find that out afterwards is to still have what came in.

## Status

`needed`, `not_needed`, `received`, `refused`, `approved`, `rejected`,
`expired`, `superseded`.

`needed` is the one that justifies the entity: a document somebody has been
asked for and has not sent is still a thing the system must hold an opinion
about, and a file cannot represent the absence of itself. `superseded` is the
other — replacing a document must not mean destroying the one it replaced.

A plain list rather than a workflow, because the transitions differ by document
type and by whose document it is, and baking one set of them in decides that for
everybody.

## `analysis_data`

Whatever has been worked out about the document — extracted text, a parse, a
model's answer — with what produced it. It belongs with the document rather than
in a cache that can be cleared.

A `map`, so it is an array in PHP and serialized in storage, which means it is
not queryable. CounselKit's D7 original used a real MySQL `JSON` column and can
query it. When something here needs that, the column type is the change rather
than the shape of the data.

## Types

A config bundle, because a CV and a bank statement share nothing but a file
field. A `general` type ships so the module is usable on install; add your own
before it accumulates everything.

## Choosing a document instead of uploading one again

`document_selector` is a widget for an `entity_reference` field pointing at
documents. It offers the documents this person has already given us, of the
right type, alongside an upload for a new one — and asks what to call a new one,
so next time the list is a choice rather than three files called `document.pdf`.

Configure it with the document type new uploads become, and the extensions and
size you will accept.

It serves both `entity_reference` and `entity_reference_revisions`. On a revision
field it records which version of the document was chosen, so replacing a file
later does not rewrite what was already sent somewhere.

## `owner` and `person`

The owner is whoever provided the document and is answerable for it. `person` is
whose life it describes. Usually the same; occasionally the whole point — a case
worker scanning a client's bank statement, a recruiter uploading somebody
else's CV.

Named for what it holds rather than for the relationship. A document can be
about a bank account, a property, a company; "about" would have to mean all of
them and so would mean nothing, and one field cannot hold them anyway without
`dynamic_entity_reference`. Those get fields of their own when something needs
them.

`getPersonId()` reads `person`, falling back to the owner where nobody said
otherwise, and `document_selector` offers documents on the same basis. That
fallback is why `person` is left empty rather than defaulted: "nobody said" has
to stay distinguishable from "it is theirs", or the recruiter case silently
becomes the recruiter's own document.

## What this module deliberately does not know

Whether a document contains special-category data under Article 9. That question
only means something alongside a consent model — what you are allowed to do
having been told yes — and a module that stores files should not carry half of
one. Add the field where the consent lives.

## Revisions

Replacing the file in a document creates a new revision rather than overwriting
it, so anything that referenced the old one — an application that was already
sent, say — still resolves to what it actually sent.

Revisions carry a log: who made them, when, and why. Without that, "there are
four versions of this" answers nothing, and the question asked of a document
years later is always who changed it and what they were doing.

The log is deliberately **not** inherited. `revision_log` is a revisionable
field like any other, so loading a document and asking for a new revision would
carry the previous message forward untouched - and every revision would then
claim the reason given for the first one that had a reason. That is worse than
an empty log: an empty log says nobody recorded why, an inherited one says
something false.

So `setRevisionLogMessage()` **stages** a message rather than writing it, and
`preSave()` puts whatever was staged onto the revision being written - nothing,
if nothing was staged. The field itself goes on saying what was actually stored,
so loading a document and reading its log gives the reason the current revision
was made, which is the only thing anybody wants from it.
`getPendingRevisionLogMessage()` reads what is staged, if you need it.

Who and when are stamped on every new revision, not only the ones made through
a form - otherwise a revision created by an update hook or a migration has no
author and no date.

## Named review requirements

Document types own a `reviews` map keyed by requirement name, such as `staff`,
`debtor` and `creditor`. Each definition contains its label, human instructions,
allowed machine decisions, whether it is required, reviewer eligibility, an
optional AI analysis prompt and a JSON Schema Draft 7 analysis contract.

The document-type form lists these definitions in a table. Each has its own edit
route; Add review requirement creates another. Editing a job's review item only
selects the named requirement and maps its document context. The job does not
copy or override the type's policy.

Eligibility has two modes, both in addition to `review documents` permission and
document view access:

- `permission`: require a specific permission. The default staff review requires
  `review documents as staff` (grant this to the appropriate staff roles).
- `context`: use the standard Typed Data Plus context mapping widget and handler
  to resolve one required `entity:user` from the document or an available global
  context. For example, `document.person.entity`, or a path through a consumer's
  case reference to its debtor. Missing relationships fail closed. The current
  actor must match the resolved user, even if that actor has administrative
  permissions. This neither logs in as that person nor grants them access.

These are workflow capacities, not Drupal role assignments. Consumers provide
case/debtor/creditor fields; the reusable document module defines none of them.
This slice maps one person per named requirement. Distinct people whose approvals
are all required should have distinct named requirements.

## Individual evidence and requirement status

`document_review` records one person's review. It stores the named requirement
in `role`, its historical label in `role_label`, the document and reviewed
revision, executing user, machine decision and historical decision label, reason,
time and revision/file-reference fingerprint. It accepts opaque source/attempt
IDs. Storage rejects updates; a new attempt creates new evidence rather than
rewriting old evidence. A unique receipt prevents source/attempt replay.

`document.reviewer::record()` reloads the document, checks access and eligibility,
verifies the fingerprint and validates the decision. Reviewer identity always
comes from the executing account. Supplying optional structured analysis validates
it against this named definition's schema and preserves both JSON data and that
schema with the evidence. This keeps old analysis interpretable if the type's
schema changes later. Human review may omit analysis; no model run is implied by
this human submission API.

`requirements($document)` returns each required definition's label, `met` flag
and the matching review UUID, if any. It uses the latest evidence for that
requirement and current document fingerprint, restricted to the current mapped
person where applicable. Only `approved` satisfies full-scope approval. It does
not mutate `document.status`, create tasks or authorize viewing other documents.

`partial` accepts a narrower scope, which a subsequent workflow must describe and
request the remainder of. `incomplete` rejects an insufficient submission while
allowing a continuation to retain uploaded material. Neither approves the
original full scope. This slice records the distinction; it does not choose
between new revisions and replacement requests or perform those follow-up effects.

The fingerprint identifies the revision and ordered file field values, ignoring
empty field items; it is not a file-byte hash. Replace managed files rather than
changing bytes in place, and retain document revisions for historical evidence.
Empty-file fingerprints are now normalized, so old empty-file approvals may need
reviewing again; evidence is never silently retargeted to a different version.

## AI preparation boundary

`prepareAnalysis($document, $name)` authorizes the named review and returns:

- A prompt resolved with the shared Typed Data Plus placeholder resolver, using
  the current document typed data (for example `{{document.label.value}}`).
- The analysis schema, allowed decisions, review name and document fingerprint.

Unresolved placeholders fail rather than silently removing review checks. Schemas
must be inline Draft 7 objects; references and base URIs are rejected. This keeps
the schema valid both alone and nested inside checklist operation parameters.
The complete schema is validated against the JSON-schema library's bundled
meta-schema. Required properties and enums are enforced when analysis is supplied.

There is **no AI runner or model invocation in this slice**. A later adapter must
record its run, model and execution authorization explicitly; it must not use the
human-record API to pretend a staff member reviewed something they did not. Human
confirmation of AI recommendations should link to that analysis/run. Prompt
configuration alone never grants authority to approve a debtor's document.

## Updates and integration

`document_update_10001()` installs review storage. `document_update_10002()` adds
analysis and historical role-label fields and converts the original single review
policy into a named staff definition, preserving instructions and decisions.
Existing records remain untouched. Existing non-staff role strings need matching
consumer-owned definitions before they can fulfil requirements.

Enable `document_checklist` for the checklist forms, resources and operation.

## Review completion and workflows

`document.reviewer::summary($document)` exposes `status` (`pending`, `complete`,
`not_required`), `met`, `total` and the per-requirement evidence. It evaluates the
current document version and current type configuration. The **Required reviews**
extra display component shows this summary in document views, including checklist
resources; hide or reorder it through Manage display. It does not expose analysis,
review reasons or reviewer identities. The summary is not render-cached because
reviewer mappings may use global contexts or related entities.

Recording a review through `document.reviewer::record()` emits
`ReviewRequirementsCompleted` only when the required set changes from incomplete
to fully approved. No required definitions means `not_required`, not completion.
The event carries the reviewed document and matching requirement evidence. Review
recording and event consumers run in the same transaction; an exception rolls back
the review and database effects. Event subscribers must not perform irreversible
external operations there: persist work to dispatch after commit instead.

Approval followed by rejection and a later approval can produce a new completion.
A new document revision requires fresh evidence. Editing type configuration or
importing review entities directly does not manufacture historical completion
occurrences. Integrations submitting decisions must use the review service.

Enable **Document Task** (`document_task`) to expose
`document.reviews_completed` in the existing dependency widget and Entity Template
dependency component. Its required context is **Reviewed document**. With
`task_dependency_job` enabled, the same event appears among job triggers as
`dependency_event:document.reviews_completed`; its `document` context can populate
the configured task template. Existing trigger actions and conditions still apply.

Dependencies retain the established *remembered occurrence* semantics: register
before completion to wait for that event. Registering after completion waits for
a later event. Replacing a document or losing approval changes the live summary
but does not erase an existing dependency receipt, reopen resolved work, or undo
an action. This is not a continuous “document remains approved” gate. A consumer
requiring that guarantee must check current requirements before acting.

Neither the event nor the summary changes `document.status`. Partial/incomplete
follow-up requests, status policy and AI execution remain separate integrations.
