define(function(require) {
    'use strict';

    const $ = require('jquery');
    const _ = require('underscore');
    const BaseView = require('oroui/js/app/views/base/view');
    const errorHandler = require('oroui/js/error');

    const EmailBodyView = BaseView.extend({
        autoRender: true,

        /**
         * @type {string}
         */
        bodyContent: null,

        /**
         * @type {Array.<string>}
         */
        styles: null,

        events: {
            'click .email-extra-body-toggle': 'onEmailExtraBodyToggle'
        },

        listen: {
            'layout:reposition mediator': '_updateHeight'
        },

        /**
         * @inheritdoc
         */
        constructor: function EmailBodyView(options) {
            EmailBodyView.__super__.constructor.call(this, options);
        },

        /**
         * @inheritdoc
         */
        initialize: function(options) {
            _.extend(this, _.pick(options, ['bodyContent', 'styles']));
            this.$frame = this.$el;
            this.$frame.on('emailShown', _.bind(this._updateHeight, this));
            this.$frame.on('load', this.reattachBody.bind(this));
            this.setElement(this.$el.contents().find('html'));
            EmailBodyView.__super__.initialize.call(this, options);
        },

        /**
         * @inheritdoc
         */
        dispose: function() {
            if (this.disposed) {
                return;
            }
            if (this.$frame) {
                this.$frame.off();
            }
            delete this.$frame;
            EmailBodyView.__super__.dispose.call(this);
        },

        /**
         * @inheritdoc
         */
        render: function() {
            let $content;
            let content = this.bodyContent;
            try {
                /**
                 * Valid email could not contain a root node after HTML tags strip,
                 * but it is still valid HTML fragment and it should be displayed like an HTML
                 *
                 * Example:
                 * Original email:
                 * <html>
                 *     <head>
                 *         <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
                 *     </head>
                 *     <body>
                 *         Some Text <b>Some other text</b>
                 *     </body>
                 * </html>
                 *
                 * Content after strip:
                 * Some Text <b>Some other text</b>
                 *
                 * This content will not be correctly parsed by JQuery, but should be displayed as an HTML
                 */
                const contentWithRootNode = '<div>' + content + '</div>';
                $content = $(contentWithRootNode);
            } catch (e) {
                errorHandler.showErrorInConsole(e);
                // if content can not be processed as HTML, output it as plain text
                content = content.replace(/[&<>]/g, function(c) {
                    return '&#' + c.charCodeAt(0) + ';';
                });
                $content = $('<div class="plain-text">' + content + '</div>');
            }
            this.$('body').html($content);
            this._injectStyles();
            this._markEmailExtraBody();
            this._updateHeight();
            return this;
        },

        /**
         * Fixes issue when iframe get empty after DOM-manipulation
         */
        reattachBody: function() {
            this.undelegateEvents();
            this.$frame.contents().find('html').replaceWith(this.$el);
            this.delegateEvents();
        },

        /**
         * Add application styles to the iframe document
         *
         * @protected
         */
        _injectStyles: function() {
            if (!this.styles) {
                return;
            }
            const $head = this.$('head');
            _.each(this.styles, function(src) {
                $('<link/>', {rel: 'stylesheet', href: src}).appendTo($head);
            });
        },

        /**
         * Updates height for iFrame element depending on the email content size
         *
         * @protected
         */
        _updateHeight: function() {
            const $frame = this.$frame;
            const $el = this.$el;
            _.delay(function() {
                $frame.height(0);
                $frame.height($el[0].scrollHeight);
            }, 50);
        },

        /**
         * Marks email extra-body part and adds the toggler
         *
         * @protected
         */
        _markEmailExtraBody: function() {
            const $extraBodies = this.$('body>.quote, body>.gmail_extra')
                .not('.email-extra-body')
                .addClass('email-extra-body');
            $('<div class="email-extra-body-toggle"></div>').insertBefore($extraBodies);
        },

        /**
         * Handles click on email extra-body toggle button
         * @param {jQuery.Event} e
         */
        onEmailExtraBodyToggle: function(e) {
            this.$(e.currentTarget)
                .next()
                .toggleClass('in');
            this._updateHeight();
        }
    });

    return EmailBodyView;
});
