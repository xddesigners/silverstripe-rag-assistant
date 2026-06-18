<% require javascript('xddesigners/silverstripe-rag-assistant: client/dist/js/assistant.js') %>
<% require css('xddesigners/silverstripe-rag-assistant: client/dist/css/assistant.css') %>

<div class="assistant-widget js-assistant"
     data-endpoint="/api/assistant/ask"
     data-offline="<% if $AssistantOffline %>1<% else %>0<% end_if %>"
     data-i18n-searching="<%t Assistant.Searching 'Searching…' %>"
     data-i18n-error="<%t Assistant.Error 'An error occurred, please try again.' %>"
     data-i18n-connection-error="<%t Assistant.ConnectionError 'Connection error, please try again.' %>"
     data-i18n-offline="<%t Assistant.Offline 'Offline' %>"
     data-i18n-offline-message="<%t Assistant.OfflineMessage 'The assistant is temporarily unavailable.' %>"
     data-max-length="$AssistantMaxLength">

    <div class="assistant-widget__panel" hidden>

        <div class="assistant-widget__header">
            <div class="assistant-widget__bot-avatar">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                </svg>
            </div>
            <div class="assistant-widget__header-text">
                <p class="assistant-widget__title"><%t Assistant.Title 'Assistant' %></p>
                <p class="assistant-widget__online"><span class="assistant-widget__online-dot"></span><%t Assistant.Online 'Online' %></p>
            </div>
        </div>

        <div class="assistant-widget__messages js-assistant-messages">

            <div class="assistant-widget__message">
                <div class="assistant-widget__message-avatar" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                    </svg>
                </div>
                <div class="assistant-widget__message-bubble">
                    <%t Assistant.Subtitle 'Ask a question and we will point you to the right information.' %>
                </div>
            </div>

            <div class="assistant-widget__message js-assistant-typing" hidden>
                <div class="assistant-widget__message-avatar" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                    </svg>
                </div>
                <div class="assistant-widget__message-bubble assistant-widget__typing">
                    <span></span><span></span><span></span>
                </div>
            </div>

            <div class="assistant-widget__message js-assistant-result" hidden>
                <div class="assistant-widget__message-avatar" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                    </svg>
                </div>
                <div class="assistant-widget__message-bubble">
                    <p class="assistant-widget__answer js-assistant-answer"></p>
                    <a class="assistant-widget__more-btn js-assistant-more-btn" href="#" target="_blank" hidden>
                        <%t Assistant.MoreInfo 'More information' %>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M4.25 5.5a.75.75 0 0 0-.75.75v8.5c0 .414.336.75.75.75h8.5a.75.75 0 0 0 .75-.75v-4a.75.75 0 0 1 1.5 0v4A2.25 2.25 0 0 1 12.75 17h-8.5A2.25 2.25 0 0 1 2 14.75v-8.5A2.25 2.25 0 0 1 4.25 4h5a.75.75 0 0 1 0 1.5h-5Zm6.75-3a.75.75 0 0 1 .75-.75h3.75a.75.75 0 0 1 .75.75v3.75a.75.75 0 0 1-1.5 0V3.81L10.03 8.78a.75.75 0 0 1-1.06-1.06l4.97-4.97h-1.74a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                        </svg>
                    </a>
                </div>
            </div>

        </div>

        <div class="assistant-widget__status js-assistant-status" aria-live="polite"></div>

        <div class="assistant-widget__input-area">
            <form class="assistant-widget__form js-assistant-form pristine-disabled" novalidate>
                <input
                    type="text"
                    class="assistant-widget__input js-assistant-input"
                    placeholder="<%t Assistant.Placeholder 'E.g. what is a council member allowance?' %>"
                    aria-label="<%t Assistant.InputLabel 'Ask a question' %>"
                />
                <button type="submit" class="assistant-widget__btn js-assistant-btn" aria-label="<%t Assistant.Button 'Search' %>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" fill="currentColor" aria-hidden="true">
                        <path d="M110.5 67L586.2 286C599.5 292.1 608 305.4 608 320C608 334.6 599.5 347.9 586.2 354L110.5 573C106.2 575 101.5 576 96.8 576C78.7 576 64 561.2 64 543C64 538.4 65 533.8 66.8 529.6L138.8 367.7C143.5 357.1 153.7 349.8 165.3 348.8L322 335.2C329.9 334.5 335.9 327.9 335.9 320C335.9 312.1 329.8 305.5 322 304.8L165.3 291.2C153.7 290.2 143.5 283 138.8 272.3L66.8 110.4C65 106.2 64 101.6 64 97C64 78.8 78.7 64 96.8 64C101.5 64 106.2 65 110.5 67z"/>
                    </svg>
                </button>
            </form>
        </div>

    </div>

    <button class="assistant-widget__toggle js-assistant-toggle"
            aria-expanded="false"
            aria-label="<%t Assistant.InputLabel 'Ask a question' %>"
            data-tooltip="<%t Assistant.Title 'Find the right page quickly' %>">
        <span class="assistant-widget__toggle-status" aria-hidden="true"></span>
        <svg class="assistant-widget__toggle-icon-open" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" fill="currentColor" aria-hidden="true">
            <path d="M576 304C576 436.5 461.4 544 320 544C282.9 544 247.7 536.6 215.9 523.3L97.5 574.1C88.1 578.1 77.3 575.8 70.4 568.3C63.5 560.8 62 549.8 66.8 540.8L115.6 448.6C83.2 408.3 64 358.3 64 304C64 171.5 178.6 64 320 64C461.4 64 576 171.5 576 304z"/>
        </svg>
        <svg class="assistant-widget__toggle-icon-close" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
        </svg>
    </button>

</div>
