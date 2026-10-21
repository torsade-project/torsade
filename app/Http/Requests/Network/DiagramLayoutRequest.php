<?php

// torsade/app/Http/Requests/Network/DiagramLayoutRequest.php

namespace App\Http\Requests\Network;

use App\Models\DiagramPosition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The layout of a diagram placed by hand: the position of each node drawn,
 * all at once, with the version of the layout it was read at. Each diagram
 * names its nodes its own way, and says in its own words what is wrong.
 */
abstract class DiagramLayoutRequest extends FormRequest
{
    /**
     * The most nodes a layout can place at once, well above what a site
     * draws.
     */
    public const int MAX_POSITIONS = 2000;

    /**
     * The field of a line that names its node.
     */
    abstract protected function nodeField(): string;

    /**
     * What the node of a line must be, besides being there once.
     *
     * @return list<string>
     */
    abstract protected function nodeRules(): array;

    /**
     * What is said of a node out of the bounds, off the grid, placed twice,
     * and of too many nodes at once.
     *
     * @return array{outOfBounds: string, offTheGrid: string, twice: string, tooMany: string}
     */
    abstract protected function phrases(): array;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', DiagramPosition::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $coordinate = ['required', 'integer', 'between:'.-DiagramPosition::LIMIT.','.DiagramPosition::LIMIT, 'multiple_of:'.DiagramPosition::GRID];
        $node = $this->nodeField();

        return [
            'version' => ['required', 'string', 'max:64'],
            'positions' => ['required', 'array', 'min:1', 'max:'.self::MAX_POSITIONS],
            'positions.*' => ['required', 'array:'.$node.',x,y'],
            'positions.*.'.$node => ['required', ...$this->nodeRules(), 'distinct'],
            'positions.*.x' => $coordinate,
            'positions.*.y' => $coordinate,
        ];
    }

    /**
     * Whatever is wrong in a layout comes from a page that is out of date:
     * the message says so, never which rule of which line failed.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $phrases = $this->phrases();
        $unreadable = __('The layout could not be read: reload the page.');
        $node = $this->nodeField();

        return [
            'version.*' => __('The layout has been changed in the meantime: reload the page.'),
            'positions.required' => $unreadable,
            'positions.array' => $unreadable,
            'positions.min' => $unreadable,
            'positions.max' => $phrases['tooMany'],
            'positions.*.required' => $unreadable,
            'positions.*.array' => $unreadable,
            'positions.*.'.$node.'.distinct' => $phrases['twice'],
            'positions.*.'.$node.'.*' => $unreadable,
            'positions.*.x.between' => $phrases['outOfBounds'],
            'positions.*.y.between' => $phrases['outOfBounds'],
            'positions.*.x.multiple_of' => $phrases['offTheGrid'],
            'positions.*.y.multiple_of' => $phrases['offTheGrid'],
            'positions.*.x.*' => $unreadable,
            'positions.*.y.*' => $unreadable,
        ];
    }

    /**
     * The lines of the layout, as they were validated.
     *
     * @return list<array{node: string, x: int, y: int}>
     */
    protected function lines(): array
    {
        $lines = [];

        /** @var list<array<string, int|string>> $validated */
        $validated = $this->validated('positions');

        foreach ($validated as $line) {
            $lines[] = ['node' => (string) $line[$this->nodeField()], 'x' => (int) $line['x'], 'y' => (int) $line['y']];
        }

        return $lines;
    }

    public function version(): string
    {
        return (string) $this->validated('version');
    }
}
