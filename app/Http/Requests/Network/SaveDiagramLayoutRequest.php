<?php

// torsade/app/Http/Requests/Network/SaveDiagramLayoutRequest.php

namespace App\Http\Requests\Network;

/**
 * The layout of the physical diagram placed by hand: the position of each
 * node drawn, by its key on the diagram: "device-12" or "patch_panel-3".
 */
class SaveDiagramLayoutRequest extends DiagramLayoutRequest
{
    protected function nodeField(): string
    {
        return 'node';
    }

    protected function nodeRules(): array
    {
        return ['string', 'max:32', 'regex:/^(?:device|patch_panel)-[1-9][0-9]{0,18}$/'];
    }

    protected function phrases(): array
    {
        return [
            'outOfBounds' => __('An element is placed out of the bounds of the diagram: reload the page.'),
            'offTheGrid' => __('An element is placed off the grid of the diagram: reload the page.'),
            'twice' => __('An element is placed twice: reload the page.'),
            'tooMany' => __('Too many elements are placed at once: reload the page.'),
        ];
    }

    /**
     * The position of each node, by its key on the diagram.
     *
     * @return array<string, array{x: int, y: int}>
     */
    public function positions(): array
    {
        $positions = [];

        foreach ($this->lines() as $line) {
            $positions[$line['node']] = ['x' => $line['x'], 'y' => $line['y']];
        }

        return $positions;
    }
}
