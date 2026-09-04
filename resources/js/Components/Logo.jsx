import logoUrl from '../../images/logo.png';
import logoIconUrl from '../../images/logo-icon.png';

// The real brand mark (raster — 300x300 source, no vector available yet, but genuinely
// transparent this time — the first version we got had a solid white background baked in, which
// couldn't blend onto the dark Login hero panel or be recolored for the translucent background
// echo the way an SVG can).
//
// `icon`: the source file is the full lockup (mountain mark + "MANAFIE AGRICULTURE" wordmark +
// tagline) as one flat square image — squeezing that whole thing down to a 32-40px nav icon
// reduces the text to an illegible smudge. logo-icon.png is a tight, non-square crop of just the
// mark (its bounding box was found programmatically via the alpha channel, not eyeballed), for
// exactly those small slots; render it with a fixed height and auto width rather than forcing it
// into a square.
export default function Logo({ className, icon = false, ...props }) {
    return <img src={icon ? logoIconUrl : logoUrl} alt="Manafie Agriculture" className={className} {...props} />;
}
