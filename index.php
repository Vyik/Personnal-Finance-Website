<?php
    require __DIR__ . '/vendor/autoload.php';

    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $servername = $_ENV['DB_HOST'];
    $username = $_ENV['DB_USER'];
    $password = $_ENV['DB_PASS'];
    $dbname = $_ENV['DB_NAME'];

	$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
	if ($conn->connect_error) {
		// Si on est en mode API, renvoyer JSON d'erreur ; sinon continuer pour afficher la page (ou afficher message)
		if (isset($_REQUEST['action'])) {
			http_response_code(500);
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(['error' => 'DB connection failed: ' . $conn->connect_error]);
			exit;
		}
		die("Erreur de connexion à la base de données: " . $conn->connect_error);
	}

	// Vérification de la table expenses
	$res = $conn->query("SHOW TABLES LIKE 'expenses'");
	if (!$res || $res->num_rows == 0) {
		die("La table 'expenses' n'existe pas dans la base de données.");
	}

	// Lors de la vérification de la table, vérifier aussi la colonne "type"
	$res = $conn->query("SHOW COLUMNS FROM expenses LIKE 'type'");
	if (!$res || $res->num_rows == 0) {
		// Ajout automatique de la colonne si absente
		$conn->query("ALTER TABLE expenses ADD COLUMN type ENUM('abonnement','charge') NOT NULL DEFAULT 'charge'");
	}

	// Vérification de la table revenues (salaire, aides, remboursements)
	$res = $conn->query("SHOW TABLES LIKE 'revenues'");
	if (!$res || $res->num_rows == 0) {
		// Création auto si absente
		$conn->query("CREATE TABLE revenues (
			id VARCHAR(32) PRIMARY KEY,
			label VARCHAR(64) NOT NULL,
			amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			type ENUM('salaire','aide','remboursement') NOT NULL DEFAULT 'salaire',
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		)");
	}

	// Helper pour renvoyer JSON
	function json_resp($data, $code = 200) {
		http_response_code($code);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($data);
		exit;
	}

	$action = $_REQUEST['action'] ?? null;
	if ($action) {
		// API endpoints
		if ($action === 'list') {
			$rows = [];
			$stmt = $conn->prepare("SELECT id, name, amount, paid, type FROM expenses ORDER BY created_at ASC");
			if ($stmt) {
				$stmt->execute();
				$res = $stmt->get_result();
				if (!$res) {
					json_resp(['error' => 'Erreur lors de la récupération des données: ' . $conn->error], 500);
				}
				while ($r = $res->fetch_assoc()) $rows[] = $r;
				$stmt->close();
			} else {
				json_resp(['error' => 'Erreur prepare: ' . $conn->error], 500);
			}
			json_resp($rows);
		}

		if ($action === 'add') {
			$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
			$name = trim($payload['name'] ?? '');
			$amount = isset($payload['amount']) ? floatval($payload['amount']) : 0;
			$type = in_array($payload['type'] ?? '', ['abonnement','charge']) ? $payload['type'] : 'charge';
			if ($name === '' ) json_resp(['error'=>'Nom requis'], 400);
			$id = bin2hex(random_bytes(8)); // id simple unique
			$stmt = $conn->prepare("INSERT INTO expenses (id, name, amount, paid, type) VALUES (?, ?, ?, 0, ?)");
			if (!$stmt) json_resp(['error'=>'prepare_failed'], 500);
			$stmt->bind_param('ssds', $id, $name, $amount, $type);
			$ok = $stmt->execute();
			$stmt->close();
			if (!$ok) json_resp(['error'=>'insert_failed'], 500);
			json_resp(['id'=>$id,'name'=>$name,'amount'=>number_format($amount,2,'.',''),'paid'=>0,'type'=>$type]);
		}

		if ($action === 'toggle') {
			$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
			$id = $payload['id'] ?? null;
			if (!$id) json_resp(['error'=>'id manquant'], 400);
			// bascule paid
			$stmt = $conn->prepare("UPDATE expenses SET paid = (1 - paid) WHERE id = ?");
			$stmt->bind_param('s', $id);
			$stmt->execute();
			$stmt->close();
			// récupérer nouvel état
			$stmt = $conn->prepare("SELECT paid FROM expenses WHERE id = ? LIMIT 1");
			$stmt->bind_param('s', $id);
			$stmt->execute();
			$res = $stmt->get_result();
			$row = $res->fetch_assoc() ?: null;
			$stmt->close();
			json_resp(['id'=>$id, 'paid' => $row ? intval($row['paid']) : null]);
		}

		if ($action === 'delete') {
			$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
			$id = $payload['id'] ?? null;
			if (!$id) json_resp(['error'=>'id manquant'], 400);
			$stmt = $conn->prepare("DELETE FROM expenses WHERE id = ?");
			$stmt->bind_param('s', $id);
			$ok = $stmt->execute();
			$stmt->close();
			json_resp(['id'=>$id,'deleted'=>$ok ? 1 : 0]);
		}

		if ($action === 'reset') {
			// Mettre paid à 0 pour toutes les dépenses
			$ok = $conn->query("UPDATE expenses SET paid = 0");
			json_resp(['reset' => $ok ? 1 : 0]);
		}

		if ($action === 'update') {
			$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
			$id = $payload['id'] ?? null;
			$name = trim($payload['name'] ?? '');
			$amount = isset($payload['amount']) ? floatval($payload['amount']) : null;
			$type = in_array($payload['type'] ?? '', ['abonnement','charge']) ? $payload['type'] : 'charge';
			if (!$id || $name === '' || $amount === null || $amount < 0) json_resp(['error'=>'Paramètres invalides'], 400);
			$stmt = $conn->prepare("UPDATE expenses SET name = ?, amount = ?, type = ? WHERE id = ?");
			if (!$stmt) json_resp(['error'=>'prepare_failed'], 500);
			$stmt->bind_param('sdss', $name, $amount, $type, $id);
			$ok = $stmt->execute();
			$stmt->close();
			if (!$ok) json_resp(['error'=>'update_failed'], 500);
			json_resp(['id'=>$id,'name'=>$name,'amount'=>number_format($amount,2,'.',''),'type'=>$type]);
		}

		// API: Liste des revenus
		if ($action === 'list_revenues') {
			$rows = [];
			$stmt = $conn->prepare("SELECT id, label, amount, type FROM revenues ORDER BY created_at ASC");
			if ($stmt) {
				$stmt->execute();
				$res = $stmt->get_result();
				while ($r = $res->fetch_assoc()) $rows[] = $r;
				$stmt->close();
			}
			json_resp($rows);
		}

		// API: Ajouter un revenu
		if ($action === 'add_revenue') {
			$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
			$label = trim($payload['label'] ?? '');
			$amount = isset($payload['amount']) ? floatval($payload['amount']) : 0;
			$type = in_array($payload['type'] ?? '', ['salaire','aide','remboursement']) ? $payload['type'] : 'salaire';
			if ($label === '' || $amount < 0) json_resp(['error'=>'Label et montant requis'], 400);
			$id = bin2hex(random_bytes(8));
			$stmt = $conn->prepare("INSERT INTO revenues (id, label, amount, type) VALUES (?, ?, ?, ?)");
			if (!$stmt) json_resp(['error'=>'prepare_failed'], 500);
			$stmt->bind_param('ssds', $id, $label, $amount, $type);
			$ok = $stmt->execute();
			$stmt->close();
			if (!$ok) json_resp(['error'=>'insert_failed'], 500);
			json_resp(['id'=>$id,'label'=>$label,'amount'=>number_format($amount,2,'.',''),'type'=>$type]);
		}

		// API: Supprimer un revenu
		if ($action === 'delete_revenue') {
			$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
			$id = $payload['id'] ?? null;
			if (!$id) json_resp(['error'=>'id manquant'], 400);
			$stmt = $conn->prepare("DELETE FROM revenues WHERE id = ?");
			$stmt->bind_param('s', $id);
			$ok = $stmt->execute();
			$stmt->close();
			json_resp(['id'=>$id,'deleted'=>$ok ? 1 : 0]);
		}

		// API: Modifier un revenu
		if ($action === 'update_revenue') {
			$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
			$id = $payload['id'] ?? null;
			$label = trim($payload['label'] ?? '');
			$amount = isset($payload['amount']) ? floatval($payload['amount']) : null;
			$type = in_array($payload['type'] ?? '', ['salaire','aide','remboursement']) ? $payload['type'] : 'salaire';
			if (!$id || $label === '' || $amount === null || $amount < 0) json_resp(['error'=>'Paramètres invalides'], 400);
			$stmt = $conn->prepare("UPDATE revenues SET label = ?, amount = ?, type = ? WHERE id = ?");
			if (!$stmt) json_resp(['error'=>'prepare_failed'], 500);
			$stmt->bind_param('sdss', $label, $amount, $type, $id);
			$ok = $stmt->execute();
			$stmt->close();
			if (!$ok) json_resp(['error'=>'update_failed'], 500);
			json_resp(['id'=>$id,'label'=>$label,'amount'=>number_format($amount,2,'.',''),'type'=>$type]);
		}

		// Ajout fusionné dépense/revenu
		if ($action === 'add_item') {
			$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
			$main_type = $payload['main_type'] ?? '';
			if ($main_type === 'depense') {
				$name = trim($payload['name'] ?? '');
				$amount = isset($payload['amount']) ? floatval($payload['amount']) : 0;
				$type = in_array($payload['sub_type'] ?? '', ['abonnement','charge']) ? $payload['sub_type'] : 'charge';
				if ($name === '' ) json_resp(['error'=>'Nom requis'], 400);
				$id = bin2hex(random_bytes(8));
				$stmt = $conn->prepare("INSERT INTO expenses (id, name, amount, paid, type) VALUES (?, ?, ?, 0, ?)");
				if (!$stmt) json_resp(['error'=>'prepare_failed'], 500);
				$stmt->bind_param('ssds', $id, $name, $amount, $type);
				$ok = $stmt->execute();
				$stmt->close();
				if (!$ok) json_resp(['error'=>'insert_failed'], 500);
				json_resp(['id'=>$id,'name'=>$name,'amount'=>number_format($amount,2,'.',''),'paid'=>0,'type'=>$type]);
			} elseif ($main_type === 'revenu') {
				$label = trim($payload['name'] ?? '');
				$amount = isset($payload['amount']) ? floatval($payload['amount']) : 0;
				$type = in_array($payload['sub_type'] ?? '', ['salaire','aide','remboursement']) ? $payload['sub_type'] : 'salaire';
				if ($label === '' || $amount < 0) json_resp(['error'=>'Label et montant requis'], 400);
				$id = bin2hex(random_bytes(8));
				$stmt = $conn->prepare("INSERT INTO revenues (id, label, amount, type) VALUES (?, ?, ?, ?)");
				if (!$stmt) json_resp(['error'=>'prepare_failed'], 500);
				$stmt->bind_param('ssds', $id, $label, $amount, $type);
				$ok = $stmt->execute();
				$stmt->close();
				if (!$ok) json_resp(['error'=>'insert_failed'], 500);
				json_resp(['id'=>$id,'label'=>$label,'amount'=>number_format($amount,2,'.',''),'type'=>$type]);
			} else {
				json_resp(['error'=>'Type principal invalide'], 400);
			}
		}

		// action inconnue
		json_resp(['error'=>'action inconnue'], 400);
	}
?>
<!doctype html>
<html lang="fr">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title>Gestion des finances</title>
	<link rel="stylesheet" href="style.css">
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
	<main class="container">
		<!-- Colonne Dépenses -->
		<section>
			<h2>Dépenses</h2>
			<select id="expense-type-switch">
				<option value="abonnement">Abonnements</option>
				<option value="charge">Charges</option>
			</select>
			<ul id="expenses-list-switch" class="expenses-list" aria-live="polite"></ul>
		</section>

		<!-- Colonne centrale : valeurs et camembert -->
		<section class="synthese">
			<h2>Synthèse</h2>
			<div class="totals">
				<div>Total : <strong id="total-sum">0€</strong></div>
				<div>Payé : <strong id="paid-sum">0€</strong></div>
				<div>Restant : <strong id="remaining-sum">0€</strong></div>
			</div>
			<canvas id="pie-chart" width="220" height="220"></canvas>
			<div id="pie-legend"></div>
			<div id="important-values"></div>
		</section>

		<!-- Colonne Revenus -->
		<section>
			<h2>Revenus</h2>
			<select id="revenue-type-switch">
				<option value="all">Tous</option>
				<option value="salaire">Salaires</option>
				<option value="aide">Aides</option>
				<option value="remboursement">Remboursements</option>
			</select>
			<ul id="revenues-list-switch" class="revenues-list" aria-live="polite"></ul>
		</section>
	</main>

	<!-- Formulaire fusionné d'ajout (en haut, centré) -->
	<div class="new-item">
		<input id="new-label" placeholder="Nom ou libellé (ex : Loyer, Salaire...)" aria-label="Nom">
		<input id="new-amount" type="number" min="0" placeholder="Montant (€)" aria-label="Montant">
		<select id="new-main-type" class="type-select" aria-label="Type">
			<option value="depense" selected>Dépense</option>
			<option value="revenu">Revenu</option>
		</select>
		<select id="new-sub-type" class="type-select" aria-label="Sous-type"></select>
		<button id="add-btn" type="button">Ajouter</button>
	</div>

	<div class="actions">
		<button id="reset-btn" type="button">Réinitialiser</button>
		<button id="edit-mode-btn" type="button">Mode édition</button>
	</div>

	<script>
		// valeurs par défaut
		// DEFAULT_EXPENSES supprimé

		const expenseTypeSwitch = document.getElementById('expense-type-switch');
		const expensesListSwitch = document.getElementById('expenses-list-switch');
		const revenueTypeSwitch = document.getElementById('revenue-type-switch');
		const revenuesListSwitch = document.getElementById('revenues-list-switch');
		const totalEl = document.getElementById('total-sum');
		const paidEl = document.getElementById('paid-sum');
		const remainingEl = document.getElementById('remaining-sum');
		const resetBtn = document.getElementById('reset-btn');
		const addBtn = document.getElementById('add-btn');
		const newLabel = document.getElementById('new-label');
		const newAmount = document.getElementById('new-amount');
		const newMainType = document.getElementById('new-main-type');
		const newSubType = document.getElementById('new-sub-type');
		const editModeBtn = document.getElementById('edit-mode-btn');
		let editMode = false;

		editModeBtn.addEventListener('click', () => {
			editMode = !editMode;
			editModeBtn.textContent = editMode ? 'Quitter édition' : 'Mode édition';
			renderExpensesSwitch();
			renderRevenuesSwitch();
		});

		async function api(action, body = null) {
			const opts = { method: body ? 'POST' : 'GET', headers: {} };
			if (body) {
				opts.headers['Content-Type'] = 'application/json';
				opts.body = JSON.stringify(body);
			}
			const url = '?action=' + encodeURIComponent(action);
			const res = await fetch(url, opts);
			if (!res.ok) {
				const txt = await res.text();
				throw new Error('API error: ' + res.status + ' ' + txt);
			}
			return res.json();
		}

		async function loadItems() {
			try {
				const items = await api('list');
				if (!Array.isArray(items)) return [];
				return items.map(i => ({
					id: String(i.id),
					name: String(i.name),
					amount: Number(i.amount),
					paid: i.paid ? 1 : 0,
					type: i.type === 'abonnement' ? 'abonnement' : 'charge'
				}));
			} catch (err) {
				console.error('Impossible de charger la liste:', err);
				return [];
			}
		}

		function updateTotals(items) {
			const total = items.reduce((s,e) => s + Number(e.amount || 0), 0);
			const paid = items.reduce((s,e) => s + (e.paid ? Number(e.amount || 0) : 0), 0);
			totalEl.textContent = total.toFixed(2) + '€';
			paidEl.textContent = paid.toFixed(2) + '€';
			remainingEl.textContent = (total - paid).toFixed(2) + '€';
		}

		async function renderExpensesSwitch() {
			const items = await loadItems();
			const type = expenseTypeSwitch.value;
			expensesListSwitch.innerHTML = '';
			const filtered = items.filter(item => item.type === type);
			filtered.forEach(item => {
				const li = document.createElement('li');
				li.className = 'expense-item' + (item.paid ? ' paid' : '');

				if (editMode) {
					// Mode édition : champs éditables + bouton supprimer
					const form = document.createElement('form');
					form.className = 'edit-row';
					form.onsubmit = async (e) => {
						e.preventDefault();
						const newName = nameInput.value.trim();
						const newAmount = parseFloat(amountInput.value);
						const newType = typeSelect.value;
						if (!newName || isNaN(newAmount) || newAmount < 0) {
							alert('Nom et montant valides requis');
							return;
						}
						try {
							await api('update', {
								id: item.id,
								name: newName,
								amount: newAmount,
								type: newType
							});
							await renderExpensesSwitch();
							await renderPieChart();
							const allItems = await loadItems();
							updateTotals(allItems);
						} catch (e) {
							console.error(e);
							alert('Erreur lors de la modification.');
						}
					};

					const nameInput = document.createElement('input');
					nameInput.type = 'text';
					nameInput.value = item.name;
					nameInput.className = 'edit-name-input';
					nameInput.required = true;

					const amountInput = document.createElement('input');
					amountInput.type = 'number';
					amountInput.value = item.amount;
					amountInput.min = 0;
					amountInput.step = '0.01';
					amountInput.className = 'edit-amount-input';
					amountInput.required = true;

					const typeSelect = document.createElement('select');
					typeSelect.className = 'type-select';
					typeSelect.innerHTML = `
						<option value="abonnement"${item.type === 'abonnement' ? ' selected' : ''}>Abonnement</option>
						<option value="charge"${item.type === 'charge' ? ' selected' : ''}>Charge</option>
					`;

					const actions = document.createElement('div');
					actions.className = 'item-actions';

					const saveBtn = document.createElement('button');
					saveBtn.type = 'submit';
					saveBtn.className = 'btn btn-pay btn-save';
					saveBtn.textContent = 'Enregistrer';

					const delBtn = document.createElement('button');
					delBtn.type = 'button';
					delBtn.className = 'btn btn-del';
					delBtn.textContent = 'Supprimer';
					delBtn.addEventListener('click', async () => {
						if (!confirm('Supprimer cette dépense ?')) return;
						try {
							await api('delete', { id: item.id });
							await renderExpensesSwitch();
							await renderPieChart();
							const allItems = await loadItems();
							updateTotals(allItems);
						} catch (e) {
							console.error(e);
							alert('Erreur lors de la suppression.');
						}
					});

					actions.appendChild(saveBtn);
					actions.appendChild(delBtn);

					form.appendChild(nameInput);
					form.appendChild(amountInput);
					form.appendChild(typeSelect);
					form.appendChild(actions);

					li.appendChild(form);
				} else {
					// Affichage normal
					const content = document.createElement('div');
					content.className = 'expense-content';
					content.innerHTML = `<span class="expense-name">${escapeHtml(item.name)}</span> <span class="expense-amount">${Number(item.amount).toFixed(2)}€</span>`;
					const actions = document.createElement('div');
					actions.className = 'item-actions';
					const payBtn = document.createElement('button');
					payBtn.type = 'button';
					payBtn.className = 'btn btn-pay';
					payBtn.textContent = item.paid ? 'Annuler' : 'Payer';
					payBtn.disabled = !item.id;
					actions.appendChild(payBtn);
					li.appendChild(content);
					li.appendChild(actions);
					if (item.id) {
						payBtn.addEventListener('click', async () => {
							try {
								await api('toggle', { id: item.id });
								await renderExpensesSwitch();
								await renderPieChart();
								const allItems = await loadItems();
								updateTotals(allItems);
							} catch (e) {
								console.error(e);
								alert('Erreur lors du basculement.');
							}
						});
					}
				}
				expensesListSwitch.appendChild(li);
			});
			const allItems = await loadItems();
			updateTotals(allItems);
		}

		async function renderRevenuesSwitch() {
			const items = await loadRevenues();
			const type = revenueTypeSwitch.value;
			revenuesListSwitch.innerHTML = '';
			const filtered = type === 'all' ? items : items.filter(item => item.type === type);
			filtered.forEach(item => {
				const li = document.createElement('li');
				li.className = 'revenue-item';
				if (editMode) {
					// Mode édition : champs éditables + bouton supprimer
					const form = document.createElement('form');
					form.className = 'edit-row';
					form.onsubmit = async (e) => {
						e.preventDefault();
						const newLabel = labelInput.value.trim();
						const newAmount = parseFloat(amountInput.value);
						const newType = typeSelect.value;
						if (!newLabel || isNaN(newAmount) || newAmount < 0) {
							alert('Libellé et montant valides requis');
							return;
						}
						try {
							await api('update_revenue', {
								id: item.id,
								label: newLabel,
								amount: newAmount,
								type: newType
							});
							await renderRevenuesSwitch();
							await renderPieChart();
						} catch (e) {
							console.error(e);
							alert('Erreur lors de la modification.');
						}
					};

					const labelInput = document.createElement('input');
					labelInput.type = 'text';
					labelInput.value = item.label;
					labelInput.className = 'edit-name-input';
					labelInput.required = true;

					const amountInput = document.createElement('input');
					amountInput.type = 'number';
					amountInput.value = item.amount;
					amountInput.min = 0;
					amountInput.step = '0.01';
					amountInput.className = 'edit-amount-input';
					amountInput.required = true;

					const typeSelect = document.createElement('select');
					typeSelect.className = 'type-select';
					typeSelect.innerHTML = `
						<option value="salaire"${item.type === 'salaire' ? ' selected' : ''}>Salaire</option>
						<option value="aide"${item.type === 'aide' ? ' selected' : ''}>Aide</option>
						<option value="remboursement"${item.type === 'remboursement' ? ' selected' : ''}>Remboursement</option>
					`;

					const actions = document.createElement('div');
					actions.className = 'item-actions';

					const saveBtn = document.createElement('button');
					saveBtn.type = 'submit';
					saveBtn.className = 'btn btn-pay btn-save';
					saveBtn.textContent = 'Enregistrer';

					const delBtn = document.createElement('button');
					delBtn.type = 'button';
					delBtn.className = 'btn btn-del';
					delBtn.textContent = 'Supprimer';
					delBtn.addEventListener('click', async () => {
						if (!confirm('Supprimer ce revenu ?')) return;
						try {
							await api('delete_revenue', { id: item.id });
							await renderRevenuesSwitch();
							await renderPieChart();
						} catch (e) {
							console.error(e);
							alert('Erreur lors de la suppression.');
						}
					});

					actions.appendChild(saveBtn);
					actions.appendChild(delBtn);

					form.appendChild(labelInput);
					form.appendChild(amountInput);
					form.appendChild(typeSelect);
					form.appendChild(actions);

					li.appendChild(form);
				} else {
					// Affichage normal
					li.innerHTML = `<span class="revenue-label">${escapeHtml(item.label)}</span> <span class="revenue-amount">${item.amount.toFixed(2)}€</span>`;
				}
				revenuesListSwitch.appendChild(li);
			});
		}

		// --- REVENUS ---
		const revenuesList = document.getElementById('revenues-list');

		async function apiRevenue(action, body = null) {
			return api(action, body);
		}

		async function loadRevenues() {
			try {
				const items = await apiRevenue('list_revenues');
				if (!Array.isArray(items)) return [];
				return items.map(i => ({
					id: String(i.id),
					label: String(i.label),
					amount: Number(i.amount),
					type: i.type
				}));
			} catch (err) {
				console.error('Impossible de charger les revenus:', err);
				return [];
			}
		}

		async function renderRevenues() {
			const items = await loadRevenues();
			revenuesList.innerHTML = '';
			items.forEach(item => {
				const li = document.createElement('li');
				li.className = 'revenue-item';
				li.innerHTML = `<span class="revenue-label">${escapeHtml(item.label)}</span> <span class="revenue-amount">${item.amount.toFixed(2)}€</span> <span class="revenue-type">${item.type}</span>`;
				const delBtn = document.createElement('button');
				delBtn.type = 'button';
				delBtn.className = 'btn btn-del';
				delBtn.textContent = 'Supprimer';
				delBtn.addEventListener('click', async () => {
					if (!confirm('Supprimer ce revenu ?')) return;
					try {
						await apiRevenue('delete_revenue', { id: item.id });
					} catch (e) {
						console.error(e);
						alert('Erreur lors de la suppression.');
					}
					await renderRevenues();
					await renderPieChart();
				});
				li.appendChild(delBtn);
				revenuesList.appendChild(li);
			});
		}

		// --- PIE CHART ---
		let pieChart = null;
		async function renderPieChart() {
			const expenses = await loadItems();
			const revenues = await loadRevenues();

			// Regroupe les dépenses par type et nom
			const abonnements = expenses.filter(e => e.type === 'abonnement');
			const charges = expenses.filter(e => e.type === 'charge');
			const abonnementTotal = abonnements.reduce((s, e) => s + (Number(e.amount) || 0), 0);
			const chargeLabels = charges.map(e => e.name);
			const chargeAmounts = charges.map(e => Number(e.amount) || 0);

			const totalRevenues = revenues.reduce((s, e) => s + (Number(e.amount) || 0), 0);
			const totalPaid = expenses.reduce((s, e) => s + (e.paid ? (Number(e.amount) || 0) : 0), 0);

			const labels = [];
			const dataArr = [];
			if (abonnementTotal > 0) {
				labels.push('Abonnements');
				dataArr.push(abonnementTotal);
			}
			chargeLabels.forEach((label, idx) => {
				labels.push(label);
				dataArr.push(chargeAmounts[idx]);
			});
			const totalExpenses = abonnementTotal + chargeAmounts.reduce((a, b) => a + b, 0);
			const resteVivre = totalRevenues - totalExpenses;
			if (resteVivre > 0) {
				labels.push('Reste à vivre');
				dataArr.push(resteVivre);
			}

			const baseColors = [
				'#e74c3c', // Abonnements
				'#f39c12', '#f1c40f', '#e67e22', '#d35400', '#c0392b', '#8e44ad', '#2980b9', '#16a085', '#27ae60'
			];
			const chargeColors = [];
			for (let i = 0; i < chargeLabels.length; i++) {
				chargeColors.push(baseColors[(i + 1) % baseColors.length]);
			}
			const colors = [];
			if (abonnementTotal > 0) colors.push(baseColors[0]);
			colors.push(...chargeColors);
			if (resteVivre > 0) colors.push('#2ecc71');

			const ctx = document.getElementById('pie-chart').getContext('2d');
			const data = {
				labels,
				datasets: [{
					data: dataArr,
					backgroundColor: colors,
				}]
			};
			if (pieChart) pieChart.destroy();
			pieChart = new Chart(ctx, {
				type: 'pie',
				data,
				options: {
					responsive: false,
					plugins: {
						legend: { display: false }
					},
					maintainAspectRatio: false
				}
			});

			// Affichage de la légende sous le camembert
			const legendDiv = document.getElementById('pie-legend');
			legendDiv.innerHTML = '';
			labels.forEach((label, idx) => {
				const color = colors[idx];
				const item = document.createElement('span');
				item.className = 'pie-legend-item';
				item.innerHTML = `<span class="pie-legend-color" style="background:${color}"></span>${escapeHtml(label)}`;
				legendDiv.appendChild(item);
			});

			legendDiv.style.display = "grid";
			legendDiv.style.gridTemplateColumns = "1fr 1fr";

			const percentPaid = totalExpenses > 0 ? (totalPaid / totalExpenses * 100) : 0;
			document.getElementById('important-values').innerHTML = `
				<div><strong>Total revenus :</strong> ${totalRevenues.toFixed(2)}€</div>
				<div><strong>Total dépenses :</strong> ${totalExpenses.toFixed(2)}€</div>
				<div><strong>Payé :</strong> ${percentPaid.toFixed(1)}%</div>
				<div><strong>Reste à vivre :</strong> ${resteVivre.toFixed(2)}€</div>
			`;
		}

		// --- Appels initiaux ---
		renderExpensesSwitch();
		renderRevenuesSwitch();
		renderPieChart();

		// Ajoute ces listeners pour synchroniser l'affichage dès qu'on change le type sélectionné

		expenseTypeSwitch.addEventListener('change', async () => {
			await renderExpensesSwitch();
			await renderPieChart();
		});

		revenueTypeSwitch.addEventListener('change', async () => {
			await renderRevenuesSwitch();
			await renderPieChart();
		});

		// Fonction utilitaire pour échapper le HTML
		function escapeHtml(str) {
			return String(str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');
		}

		// Réinitialiser la liste
		resetBtn.addEventListener('click', async () => {
			if (!confirm('Réinitialiser toutes les dépenses ?')) return;
			try {
				await api('reset');
				await renderPieChart();
				await renderRevenuesSwitch();
				await renderExpensesSwitch();
			} catch (e) {
				console.error(e);
				alert('Erreur lors de la réinitialisation.');
			}
		});

		// Remplit dynamiquement les sous-types selon le type principal
		function updateSubTypeOptions() {
			const mainType = newMainType.value;
			newSubType.innerHTML = '';
			if (mainType === 'depense') {
				newSubType.innerHTML = `
					<option value="abonnement">Abonnement</option>
					<option value="charge">Charge</option>
				`;
			} else if (mainType === 'revenu') {
				newSubType.innerHTML = `
					<option value="salaire">Salaire</option>
					<option value="aide">Aide</option>
					<option value="remboursement">Remboursement</option>
				`;
			}
		}
		// Initialisation des sous-types au chargement
		updateSubTypeOptions();
		newMainType.addEventListener('change', updateSubTypeOptions);

		// Ajout d'un nouvel élément (dépense ou revenu)
		addBtn.addEventListener('click', async () => {
			const mainType = newMainType.value;
			const name = newLabel.value.trim();
			const amount = parseFloat(newAmount.value);
			const subType = newSubType.value;

			if (!name || isNaN(amount) || amount < 0) {
				alert('Veuillez saisir un nom et un montant valide.');
				return;
			}

			addBtn.disabled = true;
			try {
				await api('add_item', {
					main_type: mainType,
					name: name,
					amount: amount,
					sub_type: subType
				});
				newLabel.value = '';
				newAmount.value = '';
				await renderExpensesSwitch();
				await renderRevenuesSwitch();
				await renderPieChart();
			} catch (e) {
				console.error(e);
				alert('Erreur lors de l\'ajout.');
			}
			addBtn.disabled = false;
		});
	</script>
</body>
</html>