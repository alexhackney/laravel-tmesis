# Development

```sh
composer test      # pest
composer analyse   # phpstan level 8 with larastan
composer format    # pint
```

The reference receiver's boot smoke test shells out to `vendor/bin/testbench` and runs
only when `TMESIS_RECEIVER_SMOKE=1` is set (CI sets it on the primary matrix cell).
Everywhere else it skips, naming the variable.

## Releasing

Releasing is a merge, and the first `## ` heading of `CHANGELOG.md` decides:
`## [Unreleased]` on top means no release, whatever else the merge carries. Renaming it
to `## [X.Y.Z] - YYYY-MM-DD` is what cuts one: `X.Y.Z` is the tmesis version for a
repin (see Versioning in the README) or the next PATCH on the line for a
package-only change. For a repin the tmesis tag of that version must exist first. The
rename may ride in the work PR itself; a separate rename-only PR is no longer needed
(it existed only so tmesis CI could go green on the sha first, and that step is gone).
Merging runs the gates on the merge commit
(`.github/workflows/release.yml`), tags it `vX.Y.Z` and publishes the GitHub release
with that section as the notes, so nothing is tagged by hand. A heading already
tagged in either spelling (the bare `0.1.0` counts) is an ordinary merge.

The version rule is enforced there too, against the `PROVENANCE.md` Tag row: the
heading's MAJOR.MINOR must equal the tag's, and its PATCH must be equal (a contract
release) or greater (a package-only release). With the Tag row at `v0.3.0`, `0.3.0`
and `0.3.1` release, `0.4.0` and `0.2.9` fail the release job. A repin moves the Tag
row first, so the heading follows it rather than the other way round.
