jQuery(function($) {
    'use strict';

    if (typeof bsmBuilderData === 'undefined') {
        console.error('BlueSendMail Error: Dados do construtor de automação ausentes.');
        return;
    }

    var app = {
        init: function() {
            this.renderInitialWorkflow();
            this.bindGlobalEvents();
            this.makeSortable();
        },

        renderInitialWorkflow: function() {
            var $mainContainer = $('#bsm-workflow-container');
            $mainContainer.empty();
            
            $.each(bsmBuilderData.steps || [], (index, step) => {
                var settings = (typeof step.step_settings === 'string' && step.step_settings) ? JSON.parse(step.step_settings) : (step.step_settings || {});
                var $stepEl = this.createStepElement(step.step_type, settings);
                $mainContainer.append($stepEl);
            });

            this.appendAddButtons($('#bsm-workflow-builder'));
            this.reindexAll();
        },

        createStepElement: function(type, settings) {
            var i18n = bsmBuilderData.i18n;
            var template = $('#tmpl-bsm-step-card').html()
                .replace('[REMOVE_STEP_TITLE]', i18n.removeStep)
                .replace('[SELECT_CAMPAIGN_DESC]', i18n.selectCampaignDesc)
                .replace('[WAIT_DESC]', i18n.waitDesc)
                .replace('[MINUTE_TEXT]', i18n.minute)
                .replace('[HOUR_TEXT]', i18n.hour)
                .replace('[DAY_TEXT]', i18n.day);
            
            var $step = $(template);
            
            $step.attr('data-step-type', type);
            $step.find('.bsm-step-type-input').val(type);

            switch (type) {
                case 'action':
                    $step.find('.bsm-step-title').text(i18n.sendCampaign);
                    $step.find('.bsm-step-content-action').show();
                    this.populateCampaignSelect($step.find('.bsm-campaign-select-action'), settings.campaign_id);
                    break;
                case 'delay':
                    $step.find('.bsm-step-title').text(i18n.wait);
                    $step.find('.bsm-step-content-delay').show();
                    if (settings.value) $step.find('.bsm-delay-value').val(settings.value);
                    if (settings.unit) $step.find('.bsm-delay-unit').val(settings.unit);
                    break;
            }
            return $step;
        },

        appendAddButtons: function($container) {
            if ($container.find('.bsm-add-step-container').length === 0) {
                 var i18n = bsmBuilderData.i18n;
                 var buttonsTemplate = $('#tmpl-bsm-add-buttons').html()
                    .replace('[ADD_ACTION_TEXT]', i18n.addAction)
                    .replace('[ADD_DELAY_TEXT]', i18n.addDelay);
                 $container.append(buttonsTemplate);
            }
        },

        populateCampaignSelect: function($select, selectedId) {
            var options = '<option value="">' + bsmBuilderData.i18n.selectCampaign + '</option>';
            $.each(bsmBuilderData.campaigns, (i, campaign) => {
                options += `<option value="${campaign.id}" ${selectedId == campaign.id ? 'selected' : ''}>${campaign.title}</option>`;
            });
            $select.html(options);
        },

        bindGlobalEvents: function() {
            $('#bsm-workflow-builder').on('click', '.bsm-add-action-btn', e => this.addStepHandler('action'));
            $('#bsm-workflow-builder').on('click', '.bsm-add-delay-btn', e => this.addStepHandler('delay'));
            $('#bsm-workflow-builder').on('click', '.bsm-step-remove', e => this.removeStepHandler(e.currentTarget));
        },
        
        addStepHandler: function(type) {
            var $stepEl = this.createStepElement(type, {});
            $('#bsm-workflow-container').append($stepEl);
            this.reindexAll();
        },

        removeStepHandler: function(button) {
            $(button).closest('.bsm-workflow-step').fadeOut(300, function() {
                $(this).remove();
                app.reindexAll();
            });
        },

        makeSortable: function() {
            $('#bsm-workflow-container').sortable({
                handle: '.bsm-step-drag-handle',
                axis: 'y',
                placeholder: 'bsm-step-placeholder',
                forcePlaceholderSize: true,
                start: (event, ui) => ui.placeholder.height(ui.item.height()),
                stop: () => this.reindexAll()
            });
        },

        reindexAll: function() {
            $('#bsm-workflow-container .bsm-workflow-step').each((index, step) => {
                var $step = $(step);
                var newNamePrefix = `steps[${index}]`;

                $step.find('.bsm-step-field').each((i, field) => {
                    var $field = $(field);
                    var fieldName = $field.data('field-name');
                    $field.attr('name', `${newNamePrefix}[${fieldName}]`);
                });
            });
        }
    };

    app.init();
});

