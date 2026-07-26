(function (Q, $, window, undefined) {

/**
 * @module Assets
 */

/**
 * Renders a preview for an Assets/invoice stream.
 * When streamName is set, shows amount, currency, status, and title.
 * Clicking opens the full Assets/invoice payment dialog.
 * When streamName is empty, renders a composer for creating new invoices.
 *
 * @class Assets invoice preview
 * @constructor
 * @param {Object} [options] options to pass besides those to Streams/preview
 * @param {Object} [options.composer] Options for the composer mode
 *   @param {String} [options.composer.currency="USD"] Default currency
 *   @param {Array} [options.composer.payments=["credits","stripe"]] Default accepted methods
 *   @param {Object} [options.composer.web3=null] Default web3 config
 * @param {Object} [options.templates]
 *   @param {Object} [options.templates.view]
 *     @param {String} [options.templates.view.name='Assets/invoice/preview/view']
 *   @param {Object} [options.templates.edit]
 *     @param {String} [options.templates.edit.name='Assets/invoice/preview/edit']
 *   @param {Object} [options.templates.create]
 *     @param {String} [options.templates.create.name='Assets/invoice/preview/create']
 * @param {Q.Event} [options.onPaid] Fired after payment succeeds from the dialog
 * @param {Q.Event} [options.onInvoice] Fired when the invoice stream is loaded
 */
Q.Tool.define("Assets/invoice/preview", "Streams/preview",
function _Assets_invoice_preview(options, preview) {
	var tool = this;
	tool.preview = preview;
	var ps = preview.state;

	if (ps.creatable) {
		ps.creatable.streamType = ps.creatable.streamType
			|| 'Assets/invoice';
		ps.creatable.title = ps.creatable.title
			|| Q.text.Assets.invoice.NewInvoice;
	}

	// Wire up composer preprocessing
	if (!ps.streamName && ps.creatable) {
		ps.creatable.preprocess = tool._preprocess.bind(tool);
	}

	ps.onRefresh.add(tool.refresh.bind(tool), tool);
	ps.onComposer.add(tool.composer.bind(tool), tool);
},

{ // default options
	composer: {
		currency: 'USD',
		payments: ['credits', 'stripe'],
		web3: null
	},
	templates: {
		view: {
			name: 'Assets/invoice/preview/view',
			fields: { titleTag: 'h3' }
		},
		edit: {
			name: 'Assets/invoice/preview/edit',
			fields: { titleTag: 'h3' }
		},
		create: {
			name: 'Assets/invoice/preview/create',
			fields: {
				src: '{{Q}}/img/actions/add.png',
				titleTag: 'h3'
			}
		}
	},
	onPaid: new Q.Event(),
	onInvoice: new Q.Event()
},

{ // methods

	/**
	 * Render an existing invoice preview
	 * @method refresh
	 */
	refresh: function (stream, callback) {
		var tool = this;
		var state = tool.state;
		var ps = tool.preview.state;
		var $te = $(tool.element);

		tool.stream = stream;
		var attr = stream.getAllAttributes();
		var status = attr.status || 'pending';
		var amount = attr.amount || 0;
		var currency = attr.currency || 'USD';
		var t = Q.text.Assets.invoice || {};

		$te.attr('data-status', status);
		if (status === 'paid') {
			$te.addClass('Assets_invoice_paid');
		}

		var tpl = (ps.editable !== false && stream.testWriteLevel('suggest'))
			? 'edit'
			: 'view';

		var fields = Q.extend({}, state.templates[tpl].fields, {
			title: stream.fields.title,
			amount: amount,
			currency: currency,
			status: status,
			statusText: t[status] || status
		});

		Q.Template.render(
			state.templates[tpl].name,
			fields,
			function (err, html) {
				if (err) return;

				Q.replace(tool.element, html);
				Q.activate(tool, function () {
					Q.handle(callback, tool);
				});

				$te.off(Q.Pointer.fastclick + '.Assets_invoice_preview')
				.on(Q.Pointer.fastclick + '.Assets_invoice_preview',
					function () {
						tool.openDialog();
					}
				);
			},
			state.templates[tpl]
		);

		// Live status updates
		stream.onFieldChanged('attributes').set(function () {
			var a = stream.getAllAttributes();
			$te.attr('data-status', a.status || 'pending');
			tool.$('.Assets_invoice_preview_status')
				.text(t[a.status] || a.status);
			if (a.status === 'paid') {
				$te.addClass('Assets_invoice_paid');
			}
		}, tool);

		Q.handle(state.onInvoice, tool, [stream]);
	},

	/**
	 * Render the composer for creating a new invoice
	 * @method composer
	 */
	composer: function () {
		var tool = this;
		var state = tool.state;
		var ps = tool.preview.state;
		var composer = state.composer;
		var t = Q.text.Assets.invoice || {};

		Q.Template.render(
			state.templates.create.name,
			Q.extend({}, state.templates.create.fields, {
				title: ps.creatable.title || t.NewInvoice,
				currency: composer.currency,
				titlePlaceholder: t.TitlePlaceholder || 'What is this for?',
				amountPlaceholder: t.AmountPlaceholder || '0.00',
				submitText: t.CreateInvoice || 'Create Invoice'
			}),
			function (err, html) {
				if (err) return;

				Q.replace(tool.element, html);

				var $container = tool.$('.Streams_preview_container');
				var $form = tool.$('.Assets_invoice_composer_form');
				var $amount = tool.$('input[name=amount]');
				var $title = tool.$('input[name=title]');
				var $submit = tool.$('.Assets_invoice_composer_submit');

				// Clickable add icon opens the form
				if (ps.creatable.clickable) {
					var clo = typeof ps.creatable.clickable === 'object'
						? ps.creatable.clickable
						: {};
					$container.plugin('Q/clickable', clo);
				}

				// Show/hide form on click
				$container.on(Q.Pointer.fastclick, function (e) {
					if ($form.is(':visible')) {
						return;
					}
					$form.show();
					$amount.focus();
					e.stopPropagation();
				});

				$submit.on(Q.Pointer.fastclick, function (e) {
					e.stopPropagation();
					e.preventDefault();

					var amount = parseFloat($amount.val());
					if (!amount || amount <= 0) {
						$amount.addClass('Q_error');
						return;
					}
					$amount.removeClass('Q_error');

					tool._doCreate({
						amount: amount,
						title: $title.val().trim() || t.DefaultTitle || 'Invoice',
						currency: composer.currency,
						payments: composer.payments,
						web3: composer.web3
					});
				});
			},
			state.templates.create
		);
	},

	/**
	 * Called by Streams/preview's creatable.preprocess
	 * when the preview is in composer mode and user clicks
	 * @method _preprocess
	 * @private
	 */
	_preprocess: function (proceed, tool, event) {
		var state = tool.state;
		var composer = state.composer;
		var t = Q.text.Assets.invoice || {};

		// Show a dialog to collect invoice details
		Q.Dialogs.push({
			title: t.CreateInvoice || 'Create Invoice',
			className: 'Assets_invoice_composer_dialog',
			template: {
				name: 'Assets/invoice/preview/composer',
				fields: {
					currency: composer.currency,
					titlePlaceholder: t.TitlePlaceholder || 'What is this for?',
					amountPlaceholder: t.AmountPlaceholder || '0.00',
					submitText: t.CreateInvoice || 'Create Invoice',
					cancelText: Q.text.Q.words.Cancel || 'Cancel'
				}
			},
			onActivate: function (dialog) {
				var $amount = $('.Assets_invoice_composer_amount', dialog);
				var $title = $('.Assets_invoice_composer_title', dialog);
				var $submit = $('.Assets_invoice_composer_submit', dialog);
				var $cancel = $('.Assets_invoice_composer_cancel', dialog);

				$amount.focus();

				$submit.on(Q.Pointer.fastclick, function () {
					var amount = parseFloat($amount.val());
					if (!amount || amount <= 0) {
						$amount.addClass('Q_error');
						return;
					}
					$amount.removeClass('Q_error');
					Q.Dialogs.pop();

					proceed({
						title: $title.val().trim()
							|| t.DefaultTitle || 'Invoice',
						attributes: JSON.stringify({
							amount: amount,
							currency: composer.currency,
							status: 'pending',
							payments: composer.payments,
							web3: composer.web3
						})
					});
				});

				$cancel.on(Q.Pointer.fastclick, function () {
					Q.Dialogs.pop();
					proceed(false);
				});
			}
		});
	},

	/**
	 * Create the invoice via Q.req then let preview handle the rest
	 * @method _doCreate
	 * @private
	 */
	_doCreate: function (fields) {
		var tool = this;
		var ps = tool.preview.state;

		tool.preview.create(null, function (err) {
			if (err) {
				Q.alert(Q.firstErrorMessage(err));
			}
		});

		// Override what Streams/preview.create will send
		ps.creatable.options = Q.extend({}, ps.creatable.options, {
			title: fields.title,
			attributes: JSON.stringify({
				amount: fields.amount,
				currency: fields.currency,
				status: 'pending',
				payments: fields.payments,
				web3: fields.web3
			})
		});
	},

	/**
	 * Open the full invoice payment dialog
	 * @method openDialog
	 */
	openDialog: function () {
		var tool = this;
		var state = tool.state;
		var ps = tool.preview.state;
		var stream = tool.stream;

		Q.Dialogs.push({
			title: stream
				? stream.fields.title
				: Q.text.Assets.payment.Invoice,
			className: 'Assets_invoice_dialog',
			apply: false,
			content: Q.Tool.prepare('div', 'Assets/invoice', {
				publisherId: ps.publisherId,
				streamName: ps.streamName,
				onPaid: function (method, details) {
					Q.Dialogs.pop();
					Q.handle(state.onPaid, tool, [method, details]);
				}
			}),
			onActivate: function (dialog) {
				Q.activate(dialog);
			}
		});
	}
});

// View template — existing invoice
Q.Template.set('Assets/invoice/preview/view',
	'<div class="Assets_invoice_preview_container'
+	' Streams_preview_container Q_clearfix">'
+	'  <div class="Assets_invoice_preview_icon Streams_preview_icon">'
+	'    <span class="Assets_invoice_preview_emoji">🧾</span>'
+	'  </div>'
+	'  <div class="Streams_preview_contents">'
+	'    <{{titleTag}} class="Streams_preview_title">'
+	'      {{title}}'
+	'    </{{titleTag}}>'
+	'    <div class="Assets_invoice_preview_details">'
+	'      <span class="Assets_invoice_preview_amount">'
+	'        {{amount}} {{currency}}'
+	'      </span>'
+	'      <span class="Assets_invoice_preview_status">'
+	'        {{statusText}}'
+	'      </span>'
+	'    </div>'
+	'  </div>'
+	'</div>'
);

// Edit template — same as view for now
Q.Template.set('Assets/invoice/preview/edit',
	'<div class="Assets_invoice_preview_container'
+	' Streams_preview_container Q_clearfix">'
+	'  <div class="Assets_invoice_preview_icon Streams_preview_icon">'
+	'    <span class="Assets_invoice_preview_emoji">🧾</span>'
+	'  </div>'
+	'  <div class="Streams_preview_contents">'
+	'    <{{titleTag}} class="Streams_preview_title">'
+	'      {{title}}'
+	'    </{{titleTag}}>'
+	'    <div class="Assets_invoice_preview_details">'
+	'      <span class="Assets_invoice_preview_amount">'
+	'        {{amount}} {{currency}}'
+	'      </span>'
+	'      <span class="Assets_invoice_preview_status">'
+	'        {{statusText}}'
+	'      </span>'
+	'    </div>'
+	'  </div>'
+	'</div>'
);

// Create template — composer
Q.Template.set('Assets/invoice/preview/create',
	'<div class="Streams_preview_container Streams_preview_create Q_clearfix">'
+	'  <img src="{{{src}}}" alt="{{alt}}"'
+	'       class="Streams_preview_add Q_no_lazyload">'
+	'  <div class="Streams_preview_contents {{titleClass}}">'
+	'    <{{titleTag}} class="Streams_preview_title">'
+	'      {{title}}'
+	'    </{{titleTag}}>'
+	'  </div>'
+	'  <div class="Assets_invoice_composer_form" style="display:none">'
+	'    <div class="Assets_invoice_composer_field">'
+	'      <input name="title" type="text"'
+	'             placeholder="{{titlePlaceholder}}"'
+	'             class="Assets_invoice_composer_title">'
+	'    </div>'
+	'    <div class="Assets_invoice_composer_field'
+	'                Assets_invoice_composer_amount_field">'
+	'      <span class="Assets_invoice_composer_currency">'
+	'        {{currency}}'
+	'      </span>'
+	'      <input name="amount" type="number" step="0.01" min="0.01"'
+	'             placeholder="{{amountPlaceholder}}"'
+	'             class="Assets_invoice_composer_amount">'
+	'    </div>'
+	'    <button class="Q_button Assets_invoice_composer_submit">'
+	'      {{submitText}}'
+	'    </button>'
+	'  </div>'
+	'</div>'
);

// Dialog composer template (used by _preprocess)
Q.Template.set('Assets/invoice/preview/composer',
	'<div class="Assets_invoice_composer">'
+	'  <div class="Assets_invoice_composer_field">'
+	'    <input name="title" type="text"'
+	'           placeholder="{{titlePlaceholder}}"'
+	'           class="Assets_invoice_composer_title">'
+	'  </div>'
+	'  <div class="Assets_invoice_composer_field'
+	'              Assets_invoice_composer_amount_field">'
+	'    <span class="Assets_invoice_composer_currency">'
+	'      {{currency}}'
+	'    </span>'
+	'    <input name="amount" type="number" step="0.01" min="0.01"'
+	'           placeholder="{{amountPlaceholder}}"'
+	'           class="Assets_invoice_composer_amount">'
+	'  </div>'
+	'  <div class="Assets_invoice_composer_buttons">'
+	'    <button class="Q_button Assets_invoice_composer_submit">'
+	'      {{submitText}}'
+	'    </button>'
+	'    <button class="Q_button Assets_invoice_composer_cancel">'
+	'      {{cancelText}}'
+	'    </button>'
+	'  </div>'
+	'</div>'
);

})(Q, Q.jQuery, window);