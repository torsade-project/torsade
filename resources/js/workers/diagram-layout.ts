// torsade/resources/js/workers/diagram-layout.ts
//
// The worker that lays the diagrams out, away from the page: the search for
// a layout in layers, with ports and nested frames, can take a while on a
// large site, and the page stays responsive meanwhile. It is the engine of
// ELK, which answers the requests the page sends through the interface of
// ELK. It is part of the build and served by the application: nothing is
// loaded from elsewhere.
import 'elkjs/lib/elk-worker.min.js';
