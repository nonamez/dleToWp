jQuery(document).ready(function(){
	jQuery('#start_dleToWp').click(function(){
		jQuery('#dleToWp_transfer').show();
		start({next_method: 'user_transfer'});
		jQuery(this).remove();
	});
});

// Stupid recursion
function start(input){

	if(jQuery('#' + input.next_method).is(':hidden'))
		jQuery('#' + input.next_method).show();

	if(input.hide_image)
		jQuery('#' + input.current_method + ' img').hide();

	if(input.count)
		jQuery('#' + input.current_method + ' span').html(input.count);

	if(input.stop)
		return false;

	var data = {
		action: 'my_action',
		method_name: input.next_method
	};

	jQuery.post(ajaxurl, data, function(data){ start(data);}, 'JSON');
}