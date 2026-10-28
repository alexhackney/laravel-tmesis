# Publish keys

The control plane mints keys; the node only verifies them, with no call back here.
That makes the format a byte-for-byte agreement, which is why the package asserts
itself against the node's own generated vectors.

```php
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Keys\KeyEpoch;

$key = Tmesis::mint('show-2041-main', KeyEpoch::fromInt(7));

$key->toString();   // show-2041-main.7.a5e453ff...29e32ff2
$key->fragment();   // show-2041-main.7.a5e4...2ff2

// The node never returns or stores a key, so a publish URL template comes back with
// the placeholder still in it.
$key->substituteInto('srt://edge-venue-07:9000?streamid={key}');
```

**A key is a credential.** Nothing in this package logs one. `StreamKey` is
`Stringable` and casts to the FULL key with its MAC, so never interpolate it into a log
line or an exception message. `fragment()` is the loggable form: it is what the node
itself puts in `key_fragment`.

## The three epochs

`docs/API.md` 8.3 names three different things "epoch", and this package gives each
its own type with no shared parent or interface, so a method typed on one cannot be
handed another:

| Type | What it is |
|---|---|
| `KeyEpoch` | The credential generation a key carries. Raising the stream's `min_key_epoch` (`StreamSpec::minKeyEpoch()`) past it makes the node refuse keys minted at lower epochs on the next handshake; a publisher already attached stays. |
| `SessionEpoch` | The node's local publish generation. It moves when a publisher attaches. |
| `FencingEpoch` | Which node may write an output prefix. |

A `StreamRecord` carries two of them (`epoch()` and `minKeyEpoch()`) and they are
returned as different types on purpose.
