/*
 * @author Paul Chan / KF Software House
 * http://www.kfsoft.info
 * Version 0.5
 * Copyright (c) 2010 KF Software House
 */
(function(a){function f(a,b){var c=a.length-b.length;return c>=0&&a.lastIndexOf(b)===c}function e(c){a("#"+b.displayDivId).css("height","auto");a.ajax({url:c,success:function(c){a(".loadingIco").remove();a(".hoverClass").removeClass("hoverClass");a("#"+b.displayDivId).html(c);var d=a("#"+b.displayDivId).height();if(d<b.pageMinHeight)a("#"+b.displayDivId).css("height",b.pageMinHeight)},cache:false})}function d(c){a("#"+b.displayDivId).css("height","auto");a("#"+b.displayDivId).html("<IFRAME width=100% height=100% id='mainContentIframe' frameborder=0 src='"+c+"'></IFRAME>");a("#mainContentIframe").load(function(){a(".loadingIco").remove();a(".hoverClass").removeClass("hoverClass");a("#"+b.displayDivId).html(data)})}var b=null;var c=null;jQuery.fn.StyleSidebar=function(f){b=a.extend({},a.fn.StyleSidebar.defaults,f);c=this;var g=window.location.pathname;a(".spiter").each(function(){a(this).parent().css("padding-top","3px");a(this).parent().css("padding-bottom","3px")});if(b.bInitPage){a(this).each(function(){var b=a(this).hasClass("iframe");var c=a(this).hasClass("ajaxlink");var f=a(this).find("a");var g=f.attr("href");if(b||c){a(this).addClass("current");a(f).append("<img class='loadingIco' src='/webGui/images/loading.gif'>");if(b)d(g);else e(g);return false}})}var h=this.each(function(){var b=location.href;var c=a(this).find("a");var f=c.attr("href");c.wrapInner("<span/>");var g=a(this).hasClass("extlink");var h=a(this).hasClass("iframe");var i=a(this).hasClass("imglink");a(c).click(function(){a(".current").removeClass("current");a(".loadingIco").remove();if(!i){a(c).append("<img class='loadingIco' src='/plugins/webGui/images/loading.gif'>");a(c).parent().addClass("current")}if(g||i){}else if(h){d(f);return false}else{e(f);return false}}).mouseenter(function(){a(".hoverClass").removeClass("hoverClass");var b=a(this).parent().hasClass("current");var c=a(this).parent().hasClass("imglink");if(!b&&!c)a(this).parent().addClass("hoverClass")}).mouseout(function(){a(".hoverClass").removeClass("hoverClass")})});return h};jQuery.fn.StyleSidebar.defaults={displayDivId:"mainContent",pageMinHeight:700,bInitPage:true};})(jQuery)