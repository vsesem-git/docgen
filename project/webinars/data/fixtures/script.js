$( document ).ready(function() {
    let url = 'getWebs2.php';
    let myDate = $('meta[name="date"]').attr('content');
    let success = function (data) {
        if (data){
            let array = [];
            $.each(data, function(index, value) {
                array.push(value);
            });

            let current, prev, next;
            for(let i=0;i<array.length;i++){
                let value = array[i];
                let {date} = value;
                if (date==myDate) {
                    prev = array[i-1];
                    current = value;
                    next = array[i+1];
                }
            }
            if (prev){
                $('.js-prev').attr('href', prev.url)
            }
            if (next){
                $('.js-next').attr('href', next.url)
            }
        }
    }
    let data = {all:true}
    $.ajax({
        url,
        success,
        data,
        dataType: "json",
    })
});