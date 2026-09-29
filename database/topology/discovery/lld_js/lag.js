/*
 * LAG: one row per member port of an aggregate (IEEE 802.3ad dot3adAggPortAttachedAggID, indexed by the member's
 * ifIndex; the value is the aggregator's ifIndex). 0 means "not aggregated"; a port attached to itself is skipped.
 */

requireWalk();

var attached = table('1.2.840.10006.300.43.1.2.1.1.13'), rows = [], keys = numericKeys(attached), i, agg;

for (i = 0; i < keys.length; i++) {
	agg = parseInt(decode(attached[String(keys[i])]), 10);

	if (isNaN(agg) || agg === 0 || agg === keys[i]) {
		continue;
	}

	rows.push({'{#IFINDEX}': String(keys[i]), '{#LAG_IFINDEX}': String(agg)});
}

return JSON.stringify(rows);
