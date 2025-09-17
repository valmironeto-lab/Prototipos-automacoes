jQuery(function($) {
    'use strict';

    if (typeof bsmBuilderData === 'undefined') {
        console.error('BlueSendMail Error: Automation builder data is missing.');
        return;
    }

    var app = {
        init: function() {
            this.renderInitialWorkflow();
            this.bindGlobalEvents();
        },

        renderInitialWorkflow: function() {
            var $mainContainer = $('#bsm-workflow-container');
            $mainContainer.empty();
            this.renderSteps(bsmBuilderData.steps_tree || {}, $mainContainer);
            this.appendAddButtons($mainContainer);
            this.makeAllSortable();
            this.reindexAll();
        },

        renderSteps: function(steps, $container) {
            var stepsArray = Array.isArray(steps) ? steps : Object.values(steps);

            $.each(stepsArray, (index, step) => {
                var settings = (typeof step.step_settings === 'string' && step.step_settings) ? JSON.parse(step.step_settings) : (step.step_settings || {});
                var $stepEl = this.createStepElement(step.step_type, settings);
                $container.append($stepEl);

                if (step.step_type === 'condition' && step.children) {
                    var $yesContainer = $stepEl.find('.bsm-branch-container[data-branch="yes"]');
                    var $noContainer = $stepEl.find('.bsm-branch-container[data-branch="no"]');
                    
                    var yesChildren = $.map(step.children, child => child.branch === 'yes' ? child : null);
                    var noChildren = $.map(step.children, child => child.branch === 'no' ? child : null);

                    this.renderSteps(yesChildren, $yesContainer);
                    this.renderSteps(noChildren, $noContainer);

                    this.appendAddButtons($yesContainer);
                    this.appendAddButtons($noContainer);
                }
            });
        },

        createStepElement: function(type, settings) {
            var template = $('#tmpl-bsm-step-card').html();
            var $step = $(template);
            
            $step.attr('data-step-type', type);
            $step.find('.bsm-step-type-input').val(type);

            switch (type) {
                case 'action':
                    $step.find('.bsm-step-title').text(bsmBuilderData.i18n.sendCampaign);
                    $step.find('.bsm-step-content-action').show();
                    this.populateCampaignSelect($step.find('.bsm-campaign-select-action'), settings.campaign_id);
                    break;
                case 'delay':
                    $step.find('.bsm-step-title').text(bsmBuilderData.i18n.wait);
                    $step.find('.bsm-step-content-delay').show();
                    if (settings.value) $step.find('.bsm-delay-value').val(settings.value);
                    if (settings.unit) $step.find('.bsm-delay-unit').val(settings.unit);
                    break;
                case 'condition':
                    $step.find('.bsm-step-title').text(bsmBuilderData.i18n.if);
                    $step.find('.bsm-step-content-condition').show();
                    $step.find('.bsm-step-branches').show();
                    this.populateCampaignSelect($step.find('.bsm-campaign-select-condition'), settings.campaign_id);
                    
                    this.appendAddButtons($step.find('.bsm-branch-container[data-branch="yes"]'));
                    this.appendAddButtons($step.find('.bsm-branch-container[data-branch="no"]'));
                    break;
            }
            return $step;
        },

        appendAddButtons: function($container) {
            if ($container.children('.bsm-add-step-container').length === 0) {
                var buttonsTemplate = $('#tmpl-bsm-add-buttons').html();
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
            $(document).on('click', '.bsm-add-action-btn', e => this.addStepHandler(e.currentTarget, 'action'));
            $(document).on('click', '.bsm-add-delay-btn', e => this.addStepHandler(e.currentTarget, 'delay'));
            $(document).on('click', '.bsm-add-condition-btn', e => this.addStepHandler(e.currentTarget, 'condition'));

            $(document).on('click', '.bsm-step-remove', e => {
                $(e.currentTarget).closest('.bsm-workflow-step').fadeOut(300, () => {
                    $(e.currentTarget).closest('.bsm-workflow-step').remove();
                    this.reindexAll();
                });
            });
        },
        
        addStepHandler: function(button, type) {
            var $stepEl = this.createStepElement(type, {});
            $(button).closest('.bsm-add-step-container').before($stepEl);
            this.makeAllSortable();
            this.reindexAll();
        },

        makeAllSortable: function() {
            $('.bsm-step-container').sortable({
                handle: '.bsm-step-drag-handle',
                items: '> .bsm-workflow-step',
                connectWith: '.bsm-step-container',
                axis: 'y',
                placeholder: 'bsm-step-placeholder',
                forcePlaceholderSize: true,
                start: (event, ui) => ui.placeholder.height(ui.item.height()),
                stop: () => this.reindexAll()
            });
        },

        reindexAll: function() {
            this.recursiveReindex($('#bsm-workflow-container'), 'steps');
        },

        recursiveReindex: function($container, namePrefix) {
            $container.children('.bsm-workflow-step').each((index, step) => {
                var $step = $(step);
                var newNamePrefix = `${namePrefix}[${index}]`;

                $step.find('.bsm-step-field').each((i, field) => {
                    var $field = $(field);
                    var fieldName = $field.data('field-name');
                    $field.attr('name', `${newNamePrefix}[${fieldName}]`);
                });

                if ($step.data('step-type') === 'condition') {
                    this.recursiveReindex($step.find('.bsm-branch-container[data-branch="yes"]'), `${newNamePrefix}[yes_branch]`);
                    this.recursiveReindex($step.find('.bsm-branch-container[data-branch="no"]'), `${newNamePrefix}[no_branch]`);
                }
            });
        }
    };

    app.init();
});

