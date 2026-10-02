<?php

/**
 * GET topology.observations.get (topology-lld-part2-spec.md §8) — NEIGHBORS evidence recorded by ingest.
 *
 * Optional: outcome (one or more of applied|device_only|shadowed|conflict|ambiguous; default: everything except
 * applied), device_id (only observations resolved to that remote Device), precision (port|device: how precisely the far
 * end was identified, topology-device-level-edge-spec.md §8), limit.
 *
 * JSON only. Strings in the response originate from LLDP/CDP (model spec §9) and are data: whoever renders them
 * must escape. The JSON hex flags below only guarantee the payload is inert if it is ever embedded in HTML; they
 * are not a substitute for escaping at render time.
 */
class CControllerTopologyObservationsGet extends CController {
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'outcome' => 'array',
			'device_id' => 'id',
			'contradicted' => 'in 0,1',
			'precision' => 'in port,device',
			'limit' => 'int32|ge 1|le 5000'
		]);

		if ($ret && $this->hasInput('outcome')) {
			foreach ((array) $this->getInput('outcome') as $outcome) {
				if (!is_string($outcome) || !in_array($outcome, CTopologyPrototype::OBSERVATION_OUTCOMES, true)) {
					error(_s('Incorrect value "%1$s" for "%2$s" field.', is_scalar($outcome) ? $outcome : '', 'outcome'));

					return false;
				}
			}
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return !CWebUser::isGuest();
	}

	protected function doAction() {
		$outcomes = $this->hasInput('outcome') ? array_values((array) $this->getInput('outcome')) : null;
		$observations = CTopologyPrototype::getObservations($outcomes,
			$this->hasInput('device_id') ? $this->getInput('device_id') : null,
			(int) $this->getInput('limit', 500),
			(int) $this->getInput('contradicted', 0) === 1,
			$this->hasInput('precision') ? $this->getInput('precision') : null
		);

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode(['observations' => $observations],
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE)
		]));
	}
}
