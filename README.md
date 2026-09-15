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

## `special_category`

Whether a document contains Article 9 data — health, religious belief, and the
rest of that list — as told to us by the person who gave it to us, with the
exact question they answered and when.

It lives on the document rather than on any one use of it, because that is where
it is true: the same CV sent to three employers contains what it contains.
Asking per use means asking the same person about the same file repeatedly, with
nothing noticing if the answers differ. Here, a second use can read the answer,
and an inconsistent one is visible.

`NULL` means nobody has been asked, which is not the same as `FALSE` meaning
somebody said no. Only the second is a declaration.
