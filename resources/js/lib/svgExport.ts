// torsade/resources/js/lib/svgExport.ts
//
// A diagram saved as an SVG file, built from the one on the page: a copy of
// its elements, never a text put together by hand with names typed by
// users. The page colours its drawing through the variables of the theme;
// the file cannot follow them, so the colours are written in as values,
// and each text falls back on a generic font. A file is a document: it
// takes the light theme, as the page does when it is printed, whatever the
// one on screen.

// What the drawing takes from the theme and the stylesheet.
const PROPERTIES = [
    'fill',
    'fill-opacity',
    'stroke',
    'stroke-width',
    'stroke-dasharray',
    'stroke-linecap',
    'stroke-linejoin',
    'stroke-opacity',
    'opacity',
    'font-size',
    'font-weight',
    'font-style',
    'text-anchor',
    'dominant-baseline',
    'display',
    'visibility',
] as const;

// The generic families a list of fonts may end on.
const GENERIC_FAMILIES = ['sans-serif', 'serif', 'monospace'];

// The font of the theme a text is set in, then the generic family that
// list ends on, which a reader without that font falls back on.
const fontFamily = (computed: string): string => {
    const families = computed.split(',').map((family) => family.trim());
    const generic =
        families.find((family) => GENERIC_FAMILIES.includes(family)) ??
        'sans-serif';

    return `${families[0] ?? generic}, ${generic}`;
};

const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

// Each element of the copy is given, as attributes, the values the browser
// worked out for the same element of the page.
const inline = (original: Element, copy: Element): void => {
    const computed = getComputedStyle(original);

    for (const property of PROPERTIES) {
        const value = computed.getPropertyValue(property);

        if (value !== '') {
            copy.setAttribute(property, value);
        }
    }

    if (original instanceof SVGTextElement) {
        copy.setAttribute('font-family', fontFamily(computed.fontFamily));
    }

    [...original.children].forEach((child, index) => {
        const copied = copy.children[index];

        if (copied !== undefined) {
            inline(child, copied);
        }
    });
};

// What only serves the page goes: classes, styles, the marks of the tests
// and what a screen reader of the page is told.
const strip = (root: Element): void => {
    for (const element of [root, ...root.querySelectorAll('*')]) {
        for (const name of element.getAttributeNames()) {
            if (
                name === 'class' ||
                name === 'style' ||
                name === 'tabindex' ||
                name === 'role' ||
                name.startsWith('data-') ||
                name.startsWith('aria-')
            ) {
                element.removeAttribute(name);
            }
        }
    }
};

// The links of the page lead nowhere from a file: a link becomes a group.
const unlink = (root: Element): void => {
    for (const anchor of root.querySelectorAll('a')) {
        const group = root.ownerDocument.createElementNS(SVG_NAMESPACE, 'g');

        group.append(...anchor.childNodes);
        anchor.replaceWith(group);
    }
};

// The diagram as the text of an SVG file, in the theme the page shows.
const fileOf = (svg: SVGSVGElement, title: string): string => {
    const copy = svg.cloneNode(true) as SVGSVGElement;

    inline(svg, copy);

    for (const skipped of copy.querySelectorAll('[data-export-skip]')) {
        skipped.remove();
    }

    // The moves and the zoom of the page are not part of the drawing.
    copy.querySelector('[data-viewport]')?.removeAttribute('transform');
    unlink(copy);
    strip(copy);

    const viewBox = svg.viewBox.baseVal;

    copy.setAttribute('xmlns', SVG_NAMESPACE);
    copy.setAttribute('width', String(Math.ceil(viewBox.width)));
    copy.setAttribute('height', String(Math.ceil(viewBox.height)));

    // The background of the frame, which the page draws around the SVG.
    const background = document.createElementNS(SVG_NAMESPACE, 'rect');
    const frame = svg.parentElement ?? svg;

    background.setAttribute('x', String(viewBox.x));
    background.setAttribute('y', String(viewBox.y));
    background.setAttribute('width', String(viewBox.width));
    background.setAttribute('height', String(viewBox.height));
    background.setAttribute('fill', getComputedStyle(frame).backgroundColor);

    const heading = document.createElementNS(SVG_NAMESPACE, 'title');

    heading.textContent = title;
    copy.prepend(heading, background);

    return (
        '<?xml version="1.0" encoding="UTF-8"?>\n' +
        new XMLSerializer().serializeToString(copy)
    );
};

// The whole diagram, whatever part of it the page shows, as the text of an
// SVG file. What only serves the page, marked "data-export-skip", is left
// out.
export const diagramFile = (svg: SVGSVGElement, title: string): string => {
    const root = svg.ownerDocument.documentElement;
    const printing = root.classList.contains('print-light');

    // The values of the light theme are read, then the page is given its
    // theme back at once: nothing is drawn in between.
    root.classList.add('print-light');

    try {
        return fileOf(svg, title);
    } finally {
        if (!printing) {
            root.classList.remove('print-light');
        }
    }
};

// A name that any system accepts for a file: no accent, no space, nothing
// but letters, digits and dashes.
export const safeFileName = (text: string): string =>
    text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^A-Za-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'site';

// The text given, saved by the browser under the name given.
export const download = (
    content: string,
    fileName: string,
    type: string,
): void => {
    const url = URL.createObjectURL(new Blob([content], { type }));
    const anchor = document.createElement('a');

    anchor.href = url;
    anchor.download = fileName;
    document.body.append(anchor);
    anchor.click();
    anchor.remove();
    // Once the browser has taken the file.
    window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
};
