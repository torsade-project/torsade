<?php

// torsade/app/Services/Network/Concerns/PhrasesMessages.php

namespace App\Services\Network\Concerns;

use App\Models\AddressBlock;

/**
 * A refusal names what is in the way. These are the turns of phrase the
 * services of the addressing plan share.
 */
trait PhrasesMessages
{
    /**
     * "a, b, c and 12 more": long lists are cut so that a message stays
     * readable.
     *
     * @param  array<int, string>  $items
     */
    private function enumerate(array $items, int $listed = 10): string
    {
        $shown = implode(', ', array_slice($items, 0, $listed));
        $more = count($items) - $listed;

        return $more > 0 ? trans_choice(':items and :count more', $more, ['items' => $shown]) : $shown;
    }

    /**
     * "a and b", "a, b and c".
     *
     * @param  list<string>  $items
     */
    private function listed(array $items): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return __(':first and :last', ['first' => implode(', ', $items), 'last' => $last]);
    }

    /**
     * How a message names a block: "the block 10.1.0.0/16 of the site Head
     * office", or "the block 10.0.0.0/8 of the organisation". The site of
     * the block must be loaded.
     */
    private function blockName(AddressBlock $block): string
    {
        return $block->site === null
            ? __('the block :cidr of the organisation', ['cidr' => $block->cidr()])
            : __('the block :cidr of the site :site', ['cidr' => $block->cidr(), 'site' => $block->site->name]);
    }
}
