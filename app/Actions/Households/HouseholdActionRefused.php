<?php

namespace App\Actions\Households;

use RuntimeException;

/**
 * A household operation that the rules do not allow (last admin, used invitation, already in a
 * household…). Rendered as an error toast; the message is shown to the user.
 */
class HouseholdActionRefused extends RuntimeException {}
