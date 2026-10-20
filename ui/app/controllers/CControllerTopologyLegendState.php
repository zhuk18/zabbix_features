<?php

/**
 * POST topology.legend.state {collapsed: 0|1} (topology-1-model.md §12.2 "Legend"): the legend of the graph can be collapsed and the state is kept per user, in the user's
 * profile (`web.topology.legend.collapsed`). The same value is read when the page opens (CControllerTopologyView).
 */
class CControllerTopologyLegendState extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateJsonInput(['collapsed' => 'required|in 0,1']);
	}

	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		$collapsed = (int) $this->getInput('collapsed');
		CProfile::update(CControllerTopologyView::PROFILE_LEGEND_COLLAPSED, $collapsed, PROFILE_TYPE_INT);

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['collapsed' => $collapsed])]));
	}
}
