const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const PrivateMessagePageController = async (ctx, data, io,socket,callback) => {
  console.log('from page')
  if ((!data.msg || data.msg.trim() === "") && !data.mediaId && !data.record && !data.lng && !data.lat) {
      console.log("Message has no text, neither media, skipping")
      return
  }
  if(data.msg){
      data.msg = await funcs.sanitizeJS(data.msg);
      data.msg = data.msg.replace("\r\n", " <br>");
      data.msg = data.msg.replace("\n\r", " <br>");
      data.msg = data.msg.replace("\r", " <br>");
      data.msg = data.msg.replace("\n", " <br>");
  }

  let remainingSameUserSockets = []
  if (ctx.userIdSocket[ctx.userHashUserId[data.from_id]]) {
      remainingSameUserSockets = ctx.userIdSocket[ctx.userHashUserId[data.from_id]].filter(d => d.id != socket.id)
  }

  let lastId = await ctx.wo_messages.findOne({
      limit: 1,
      attributes: ["id"],
      where: {
          [Op.or]: [
              {
                  from_id: {
                      [Op.eq]: ctx.userHashUserId[data.from_id]
                  },
                  to_id: {
                      [Op.eq]: parseInt(data.to_id)
                  }
              },
              {
                  from_id: {
                      [Op.eq]: parseInt(data.to_id)
                  },
                  to_id: {
                      [Op.eq]: ctx.userHashUserId[data.from_id]
                  }
              }
          ]
      },
      order: [['id', 'DESC']]
  })
  let nextId = (lastId && lastId.id) ? (+lastId.id + 1) : 1
  let fromUser = await ctx.wo_users.findOne({
      where: {
          user_id: {
              [Op.eq]: ctx.userHashUserId[data.from_id]
          }
      }
  })
  let toUser = await ctx.wo_users.findOne({
      where: {
          user_id: {
              [Op.eq]: parseInt(data.to_id)
          }
      }
  })
  var story_id = 0;
  if (data.story_id && data.story_id > 0) {
      story_id = parseInt(data.story_id);
  }
  var lng = 0;
  var lat = 0;
  if (data.lng && data.lat && data.lng !== undefined && data.lat !== undefined) {
      lng = data.lng;
      lat = data.lat;
  }
  let hasHTML = false;
  let record_data = '';
  if (data.record) {
      if (data.message_reply_id > 0) {
          data = await funcs.canSendReply(ctx, data);
      }
      let ret = await ctx.wo_messages.create({
          from_id: ctx.userHashUserId[data.from_id],
          to_id: data.to_id,
          text: "",
          media: data.mediaFilename,
          mediaFileName: data.mediaName,
          seen: 0,
          time: Math.floor(Date.now() / 1000),
          reply_id: parseInt(data.message_reply_id),
          story_id: story_id,
          lng: lng,
          lat: lat,
      })
      record_data = ret;
      data.mediaId = ret.id;
      data.sent_message = ret;
      await socket.emit('private_message_page', {
          html: await compiledTemplates.messageListOwnerTrueWithMedia(ctx, data, fromUser, nextId, data.color, data.isSticker),
          id: data.to_id,
          receiver: ctx.userHashUserId[data.from_id],
          sender: ctx.userHashUserId[data.from_id],
          status: 200,
          color: data.color,
          mediaLink: funcs.Wo_GetMedia(ctx, data.mediaId),
          time: '<div class="messages-last-sent pull-right time ajax-time" title="' + moment().toISOString() + '">..</div>',
          lng: lng,
          lat: lat,
          message_id: data.sent_message.id,
          time_api: data.sent_message.time,
      });
  }
  let msg;
  ({ msg, hasHTML } = funcs.Wo_Emo(data.msg))
  data.msg = msg

  if (!data.mediaId) {
      let link_regex = new RegExp('(http\:\/\/|https\:\/\/|www\.)([^\ ]+)', 'gi');
      let mention_regex = new RegExp('@([A-Za-z0-9_]+)', 'gi');
      // let hashtag_regex = new RegExp('#([^`~!@$%^&*\#()\-+=\\|\/\.,<>?\'\":;{}\[\]* ]+)', 'gi');

      let linkSearch = msg.match(link_regex)
      if (linkSearch && linkSearch.length > 0) {
          hasHTML = true;
          for (let linkSearchOne of linkSearch) {
              let matchUrl = striptags(linkSearchOne)
              let syntax = '[a]' + escape(matchUrl) + '[/a]'
              data.msg = data.msg.replace(link_regex, syntax)
          }
      }
      let mentionSearch = msg.match(mention_regex)
      if (mentionSearch && mentionSearch.length > 0) {
          hasHTML = true;
          for (let mentionSearchOne of mentionSearch) {
              let mention = await ctx.wo_users.findOne({
                  where: {
                      username: mentionSearchOne.substr(1, mentionSearchOne.length)
                  }
              })
              if (mention) {
                  let match_replace = '@[' + mention['user_id'] + ']';
                  data.msg = data.msg.replace(mention_regex, match_replace)
              }
          }
      }
      let hashTagSearch = msg.match(/#([^`~!@$%^&*\#()\-+=\\|\/\.,<>?\'\":;{}\[\]* ]+)/gi)
      if (hashTagSearch && hashTagSearch.length > 0) {
          hasHTML = true
          for (let hashTagSearchOne of hashTagSearch) {
              let hashdata = await funcs.Wo_GetHashtag(ctx, hashTagSearchOne.substr(1))
              let replaceString = '#[' + hashdata['id'] + ']';
              data.msg = data.msg.replace(/#([^`~!@$%^&*\#()\-+=\\|\/\.,<>?\'\":;{}\[\]* ]+)/gi, replaceString)
              await ctx.wo_hashtags.update({
                  last_trend_time: Math.floor(Date.now() / 1000),
                  trend_use_num: hashdata["trend_use_num"] + 1
              },
                  {
                      where: {
                          id: hashdata['id']
                      }
                  })
          }
      }
      let sendable_message = await funcs.Wo_Markup(ctx, data.msg);
      let temp = await compiledTemplates.messageListOwnerTrue(ctx, data, fromUser, nextId, hasHTML, sendable_message, data.color)

      
      // if recepient has chat open then send last seen 
      if (ctx.userIdChatOpen[data.to_id] && ctx.userIdChatOpen[data.to_id].filter(d => d == ctx.userHashUserId[data.from_id]).length ||
          ctx.userIdExtra[data.to_id] && ctx.userIdExtra[data.to_id].active_message_user_id && +ctx.userIdExtra[data.to_id].active_message_user_id === +ctx.userHashUserId[data.from_id]) {
              if (data.message_reply_id > 0) {
                  data = await funcs.canSendReply(ctx, data);
              }
          var m_sent = await ctx.wo_messages.create({
              from_id: ctx.userHashUserId[data.from_id],
              to_id: data.to_id,
              text: await funcs.Wo_CensoredWords(ctx, data.msg),
              seen: 0,
              time: Math.floor(Date.now() / 1000),
              reply_id: parseInt(data.message_reply_id),
              story_id: story_id,
              lng: lng,
              lat: lat,
          })
          data.sent_message = m_sent;
         // await socketEvents.lastseen(ctx, socket, { seen: Math.floor(Date.now() / 1000) })
      }
      else {
          if (data.message_reply_id > 0) {
            data = await funcs.canSendReply(ctx, data);
          }
          var m_sent = await ctx.wo_messages.create({
              from_id: ctx.userHashUserId[data.from_id],
              to_id: data.to_id,
              text: await funcs.Wo_CensoredWords(ctx, data.msg),
              seen: 0,
              time: Math.floor(Date.now() / 1000),
              reply_id: parseInt(data.message_reply_id),
              story_id: story_id,
              lng: lng,
              lat: lat,
          })
          data.sent_message = m_sent;
      }
      callback({
          status: 200,
          html: temp,
          receiver: data.to_id,
          sender: ctx.userHashUserId[data.from_id],
          lng: lng,
          lat: lat,
          message_id: data.sent_message.id,
          time_api: data.sent_message.time,
      })

      // send same message to all tabs
      for (userSocket of remainingSameUserSockets) {
          await userSocket.emit('private_message', {
              messages_html: await compiledTemplates.chatListOwnerTrue(ctx, data, fromUser, nextId, hasHTML, sendable_message, data.color),
              id: data.to_id,
              username: ((fromUser && fromUser.first_name !== undefined && fromUser.first_name != '' && fromUser.last_name !== undefined && fromUser.last_name != '') ? fromUser.first_name + ' ' + fromUser.last_name : fromUser.username),
              avatar: ((fromUser && fromUser.avatar !== undefined) ? await funcs.Wo_GetMedia(ctx, fromUser.avatar) : ''),
              receiver: ctx.userHashUserId[data.from_id],
              sender: ctx.userHashUserId[data.from_id],
              status: 200,
              color: data.color,
              message: data.msg,
              message_html: sendable_message,
              time: '<div class="messages-last-sent pull-right time ajax-time" title="' + moment().toISOString() + '">..</div>',
              lng: lng,
              lat: lat,
              message_id: data.sent_message.id,
              time_api: ((data && data.sent_message !== undefined && data.sent_message.time !== undefined) ? data.sent_message.time : '') ,
          });
          await userSocket.emit('private_message_page', {
              html: await compiledTemplates.messageListOwnerTrue(ctx, data, fromUser, nextId, hasHTML, sendable_message, data.color),
              id: data.to_id,
              receiver: ctx.userHashUserId[data.from_id],
              sender: ctx.userHashUserId[data.from_id],
              status: 200,
              color: data.color,
              message: data.msg,
              message_html: sendable_message,
              time: '<div class="messages-last-sent pull-right time ajax-time" title="' + moment().toISOString() + '">..</div>',
              lng: lng,
              lat: lat,
              message_id: data.sent_message.id,
              time_api: ((data && data.sent_message !== undefined && data.sent_message.time !== undefined) ? data.sent_message.time : '') ,
          });
      }

      await socketEvents.privateMessageToPersonOwnerFalse(ctx, io, data, fromUser, nextId, hasHTML, sendable_message, data.color)
      await socketEvents.privateMessagePageToPersonOwnerFalse(ctx, io, data, fromUser, nextId, hasHTML, sendable_message, data.color)

      
      await funcs.updateOrCreate(ctx.wo_userschat, {
          user_id: ctx.userHashUserId[data.from_id],
          conversation_user_id: data.to_id,
      }, {
          time: Math.floor(Date.now() / 1000),
          user_id: ctx.userHashUserId[data.from_id],
          conversation_user_id: data.to_id,
      })
      await funcs.updateOrCreate(ctx.wo_userschat, {
          conversation_user_id: ctx.userHashUserId[data.from_id],
          user_id: data.to_id,
      }, {
          time: Math.floor(Date.now() / 1000),
          conversation_user_id: ctx.userHashUserId[data.from_id],
          user_id: data.to_id,
      })
  }
  else {
      if (data.record) {
          var m_sent = record_data;
      }
      else{
          var m_sent = await ctx.wo_messages.create({
              from_id: ctx.userHashUserId[data.from_id],
              to_id: data.to_id,
              text: await funcs.Wo_CensoredWords(ctx, data.msg),
              seen: 0,
              time: Math.floor(Date.now() / 1000),
              reply_id: parseInt(data.message_reply_id),
              story_id: story_id,
              lng: lng,
              lat: lat,
          })
      }
          
      data.sent_message = m_sent;
      for (userSocket of remainingSameUserSockets) {
          await userSocket.emit('private_message', {
              messages_html: await compiledTemplates.chatListOwnerTrueWithMedia(ctx, data, fromUser, nextId, data.color),
              id: data.to_id,
              username: ((fromUser && fromUser.first_name !== undefined && fromUser.first_name != '' && fromUser.last_name !== undefined && fromUser.last_name != '') ? fromUser.first_name + ' ' + fromUser.last_name : fromUser.username),
              avatar: ((fromUser && fromUser.avatar !== undefined) ? await funcs.Wo_GetMedia(ctx, fromUser.avatar) : ''),
              receiver: ctx.userHashUserId[data.from_id],
              sender: ctx.userHashUserId[data.from_id],
              status: 200,
              color: data.color,
              mediaLink: funcs.Wo_GetMedia(ctx, data.mediaId),
              time: '<div class="messages-last-sent pull-right time ajax-time" title="' + moment().toISOString() + '">..</div>',
              lng: lng,
              lat: lat,
              message_id: data.sent_message.id,
              time_api: data.sent_message.time,
          });
          await userSocket.emit('private_message_page', {
              html: await compiledTemplates.messageListOwnerTrueWithMedia(ctx, data, fromUser, nextId, data.color),
              id: data.to_id,
              receiver: ctx.userHashUserId[data.from_id],
              sender: ctx.userHashUserId[data.from_id],
              status: 200,
              color: data.color,
              mediaLink: funcs.Wo_GetMedia(ctx, data.mediaId),
              time: '<div class="messages-last-sent pull-right time ajax-time" title="' + moment().toISOString() + '">..</div>',
              lng: lng,
              lat: lat,
              message_id: data.sent_message.id,
              time_api: data.sent_message.time,
          });
      }
      await socketEvents.privateMessagePageToPersonOwnerFalseWithMedia(ctx, io, data, fromUser, data.isSticker)
      await socketEvents.privateMessageToPersonOwnerFalseWithMedia(ctx, io, data, fromUser, data.isSticker)
      // await ctx.wo_messages.update({
      //     seen: Math.floor(Date.now() / 1000)
      // },
      //     {
      //         where: {
      //             from_id: ctx.userHashUserId[data.from_id],
      //             to_id: data.to_id,
      //         }
      //     })
  }
};

module.exports = { PrivateMessagePageController };