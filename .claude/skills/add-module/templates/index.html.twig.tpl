{% extends '@Core/suite/layout.html.twig' %}

{% block title %}{{ 'suite.nav.{{MODULE_ID}}'|trans }} - {{ parent() }}{% endblock %}

{% block page_header_slot %}
    {{ include('@Shared/components/page_header.html.twig', {
        crumbs: [
            {label: 'suite.nav.sections.{{MODULE_ID}}'|trans},
            {label: 'suite.nav.{{MODULE_ID}}'|trans},
        ],
    }) }}
{% endblock %}

{% block body %}
    <div {{ vue_component('{{MODULE_ID}}/suite/{{MODULE}}App', {}) }} class="flex-1 min-w-0"></div>
{% endblock %}
