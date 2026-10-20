<?php

class CControllerTopologyView extends CController {
	// the legend of the graph can be collapsed; the state is kept per user (topology-1-model.md §12.2)
	public const PROFILE_LEGEND_COLLAPSED = 'web.topology.legend.collapsed';

    protected function init() {
		$this->disableCsrfValidation();
	}	
    protected function checkInput(): bool { return true; }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction() {
		$response = new CControllerResponseData(['legend_collapsed' => (int) CProfile::get(self::PROFILE_LEGEND_COLLAPSED, 0)]);
		$response->setTitle(_('Topology prototype'));
		$this->setResponse($response);
	}
}