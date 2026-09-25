(function (blocks, element, serverSideRender) {
    blocks.registerBlockType('adct/events', {
        title: 'Upcoming events',
        icon: 'calendar-alt',
        category: 'widgets',
        attributes: {
            period: { type: 'string', default: 'upcoming' }
        },
        edit: function (props) {
            return element.createElement(serverSideRender, {
                block: 'adct/events',
                attributes: props.attributes
            });
        },
        save: function () {
            return null;
        }
    });
}(wp.blocks, wp.element, wp.serverSideRender));
