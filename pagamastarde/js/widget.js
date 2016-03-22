if(window.jQuery) {
jQuery( document ).ready(function() {
  jQuery.getScript("https://cdn.pagamastarde.com/pmt-simulator/2/js/pmt-simulator.min.js");
});
}else{
  var script = document.createElement('script');
  script.src = "https://cdn.pagamastarde.com/pmt-simulator/2/js/pmt-simulator.min.js";
  setTimeout(function(){
  document.body.appendChild(script);
  },2000);  // 2000 is the delay in milliseconds
}
