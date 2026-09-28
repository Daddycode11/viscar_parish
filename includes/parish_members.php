<?php
/** Parish membership for read-only profiles and Secretary messaging. */
function parish_member_sql(int $parish): string
{
    return "(parish_id=$parish OR id IN (SELECT user_id FROM applications WHERE parish_id=$parish) OR id IN (SELECT user_id FROM parish_subscriptions WHERE parish_id=$parish AND (in_app=1 OR email=1 OR sms=1)))";
}
