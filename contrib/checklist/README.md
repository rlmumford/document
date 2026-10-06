# Document Checklist

A dedicated human-review item, `document_review`. The document module owns the
review data and policy; this adapter owns checklist forms, operations and resource
integration. There are no new API routes: enabling `checklist_api` exposes the
existing dispatcher contract.

```yaml
review:
  label: Review the agreement
  handler: document_review
  handler_configuration:
    role: client
    context_mapping:
      document: 'task_context:document'
```

The ordinary Typed Data Plus context-mapping widget selects the document. A
checklist template can map `template_context:document` instead, so the existing
collection expansion can create one review item per document. The role belongs
to trusted job/item configuration; assigning a client role here does not grant
access or select another person to impersonate. The host checklist's assignment
and access controls determine who can act.

Opening the item shows the document type's instructions and a submit button for
each named decision, with an optional reason. The shared resource pane renders
the document's normal entity view display, respecting access and display config;
it does not extract or convert file contents. Configure file formatters or other
document fields on that display as appropriate. Items for the same document share
one resource key, `document:<uuid>`.

## Action operation

Discover `review` to obtain the current choices, labels (`x-enum-labels`) and the
required fingerprint. Submit that fingerprint with the decision:

```json
{
  "decision": "approved",
  "fingerprint": "<fingerprint returned by discovery>",
  "reason": "Names and terms checked."
}
```

The form and API call the same operation. Unknown parameters, including reviewer
or role overrides, are rejected. The handler rechecks host access, applicability,
actionability, document access and the current document fingerprint. A changed
revision or file set requires a fresh review; an old open form cannot approve a
replacement. The review and checklist completion are committed together.

Outcomes are `decision` (the machine string), `review` (the document-review
entity) and `document` (the document entity). Later items can map
`item:review:decision` or navigate the review's reviewer, role and decision label.
A generic string definition is deliberate: the document type is context-selected
at runtime, while the record preserves the historical decision label.

The public action state reports the completed decision, reviewer and role. Review
records link back to the item UUID and, when an attempt exists, its UUID. This
interactive item uses the existing interactive checklist lifecycle; it does not
create an automatic worker attempt solely to collect a human decision.

## Tests

`tests/src/Kernel/DocumentReviewTest.php` covers two distinct reviewers on one
document, shared form/operation behavior, persisted typed outcomes, schema,
unchanged aggregate status, stale files, unauthorized access, identity spoofing,
separate attempts, immutable evidence and duplicate receipts. The Common workflow
runs it on SQLite and MySQL.
