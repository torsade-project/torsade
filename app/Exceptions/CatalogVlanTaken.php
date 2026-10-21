<?php

// torsade/app/Exceptions/CatalogVlanTaken.php

namespace App\Exceptions;

use App\Models\CatalogVlan;
use Illuminate\Validation\ValidationException;

/**
 * The number or the name given to an entry of the catalogue is already the
 * one of another entry.
 *
 * It is a validation error like any other, with its messages on the fields.
 * It also carries the entries in the way: someone who types a number that is
 * already in the catalogue is most often looking for that entry, and the
 * interface leads there.
 */
final class CatalogVlanTaken extends ValidationException
{
    /**
     * The entry that already has the number.
     */
    public ?CatalogVlan $byVid = null;

    /**
     * The entry that already has the name.
     */
    public ?CatalogVlan $byName = null;

    /**
     * @return $this
     */
    public function inTheWay(?CatalogVlan $byVid, ?CatalogVlan $byName): static
    {
        $this->byVid = $byVid;
        $this->byName = $byName;

        return $this;
    }
}
