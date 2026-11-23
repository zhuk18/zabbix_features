<?php declare(strict_types = 0); ?>
<script>
// The header of the last run view (topology-1-model.md 2.98, §12.1 "Page header"): the run state, the button "Run reconciliation" and the warning "The reconciliation does not run".
// The graph view does the same in its own script (monitoring.topology.view.js.php), which also reloads the graph; here a finished run reloads the page, so the summary is the new one.
const topology_header = new class {
	async request(action, options = {}) {
		if (options.method === 'POST') {
			options = {...options, body: JSON.stringify({[<?= json_encode(CSRF_TOKEN_NAME) ?>]: <?= json_encode(CCsrfTokenHelper::get('topology')) ?>})};
		}
		const response = await fetch(`zabbix.php?action=${action}`, options);
		const payload = await response.json();
		if (!response.ok || payload.error) {
			throw new Error(payload.error?.messages?.join('\n') ?? `Topology request failed: ${response.status}`);
		}
		return payload;
	}

	init() {
		this.line = document.getElementById('topology-run-line');
		this.box = document.getElementById('topology-executor-missing');
		this.active = false;
		document.getElementById('topology-ingest-run')?.addEventListener('click', async () => {
			try {
				await this.request('topology.ingest.run', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}'});
				this.poll();
			}
			catch (error) {
				alert(error.message);
			}
		});
		this.poll();
	}

	show(status) {
		const time = status.finished_at_text ?? '';
		const state = (status.running || status.status === 'running') ? <?= json_encode(_('Reconciliation in progress')) ?>
			: (status.requested ? <?= json_encode(_('Run requested')) ?> : '');
		const info = TopologyGraph.runInfoText(status.reporters_new_data ?? 0, time, {
			one: <?= json_encode(_('%1$s reporter has new data since the last run')) ?>,
			many: <?= json_encode(_('%1$s reporters have new data since the last run')) ?>,
			last: <?= json_encode(_('Last run: %1$s')) ?>
		});
		if (this.line) {
			this.line.textContent = [state, info].filter(text => text !== '').join(' · ');
		}
		if (this.box) {
			this.box.hidden = !(status.requested && status.executor_missing);
		}
	}

	async poll() {
		if (this.active) {
			return;
		}
		this.active = true;
		let was_working = false;
		for (;;) {
			let status;
			try {
				status = await this.request('topology.ingest.status');
			}
			catch (error) {
				this.active = false;
				return;
			}
			this.show(status);
			const working = !!(status.running || status.requested || status.status === 'running');
			if (!working) {
				this.active = false;
				if (was_working) {
					location.reload();      // the run has finished: the summary and the list are the new ones
				}
				else {
					setTimeout(() => this.poll(), 30000);
				}
				return;
			}
			was_working = true;
			await new Promise(resolve => setTimeout(resolve, status.running ? 2000 : (status.executor_missing ? 15000 : 3000)));
		}
	}
};
jQuery(() => topology_header.init());
</script>
