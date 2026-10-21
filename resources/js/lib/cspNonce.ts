// torsade/resources/js/lib/cspNonce.ts
//
// The nonce of the Content-Security-Policy, for the code that inserts a
// style or a script element. The policy is set when the document first
// loads; Inertia then only replaces the page, never the document, so the
// nonce of that first document stays the right one. It is read from the
// meta tag the server writes in it, where Vite reads it too, and never
// from the properties of a page.
export const cspNonce = (): string | undefined =>
    document.querySelector<HTMLMetaElement>('meta[property="csp-nonce"]')
        ?.nonce || undefined;
