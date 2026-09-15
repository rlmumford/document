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
